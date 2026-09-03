<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Core\Xrpl\DestinationTagService;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopXrplTransactionRepository;

/**
 * The persistence port, against the real database.
 */
final class XrplTransactionRepositoryTest extends IntegrationTestCase
{
    private PrestaShopXrplTransactionRepository $repository;
    private string $account;

    protected function setUp(): void
    {
        $this->repository = new PrestaShopXrplTransactionRepository();
        $this->account = 'rTest' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $db = \Db::getInstance();
        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . Installer::TABLE_TX . '`
             WHERE `destination` = "' . pSQL($this->account) . '"'
        );
        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . Installer::TABLE_DESTINATION_TAG . '`
             WHERE `destination_account` = "' . pSQL($this->account) . '"'
        );
    }

    /**
     * The port's contract: 0 on the first call for an account, then strictly
     * increasing, and independent per account.
     */
    public function testDestinationTagSequenceStartsAtZeroAndIncreases(): void
    {
        self::assertSame(0, $this->repository->nextDestinationTagSequence($this->account));
        self::assertSame(1, $this->repository->nextDestinationTagSequence($this->account));
        self::assertSame(2, $this->repository->nextDestinationTagSequence($this->account));
    }

    public function testDestinationTagSequenceIsPerAccount(): void
    {
        $this->repository->nextDestinationTagSequence($this->account);
        $this->repository->nextDestinationTagSequence($this->account);

        $other = $this->account . 'X';

        try {
            self::assertSame(0, $this->repository->nextDestinationTagSequence($other));
        } finally {
            \Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . Installer::TABLE_DESTINATION_TAG . '`
                 WHERE `destination_account` = "' . pSQL($other) . '"'
            );
        }
    }

    public function testGeneratedTagsAreDistinctAndWithinTheUnsigned32BitRange(): void
    {
        $service = new DestinationTagService($this->repository);

        $tags = [];
        for ($i = 0; $i < 5; ++$i) {
            $tags[] = $service->generateDestinationTag($this->account);
        }

        self::assertCount(5, array_unique($tags));
        foreach ($tags as $tag) {
            self::assertGreaterThanOrEqual(0, $tag);
            self::assertLessThanOrEqual(4294967295, $tag);
        }
    }

    /**
     * The payload columns hold JSON. pSQL() would run it through
     * strip_tags(nl2br()) and quietly mangle anything containing "<" or a
     * newline, which is why the repository escapes them differently.
     */
    public function testTransactionPayloadSurvivesHostileJson(): void
    {
        $meta = [
            'memo' => 'a < b & "c"',
            'multiline' => "line one\nline two",
            'backtick' => 'back`tick',
            'delivered_amount' => '15063780',
        ];

        $this->repository->saveTransactions([$this->transaction('A', 4242, $meta)]);

        $found = $this->repository->findTransaction($this->account, 4242);

        self::assertNotNull($found);
        self::assertSame($meta, $found->meta);
    }

    public function testSavingTheSameHashTwiceStoresOneRow(): void
    {
        $transaction = $this->transaction('B', 99);

        $this->repository->saveTransactions([$transaction]);
        $this->repository->saveTransactions([$transaction]);

        self::assertSame([$transaction->hash], $this->repository->findExistingHashes([$transaction->hash]));
        self::assertSame(1, $this->countRows());
    }

    public function testFindExistingHashesReportsOnlyWhatIsStored(): void
    {
        $stored = $this->transaction('C', 7);
        $this->repository->saveTransactions([$stored]);

        self::assertSame(
            [$stored->hash],
            $this->repository->findExistingHashes([$stored->hash, str_repeat('0', 64)])
        );
        self::assertSame([], $this->repository->findExistingHashes([]));
    }

    public function testUnknownDestinationTagFindsNothing(): void
    {
        $this->repository->saveTransactions([$this->transaction('D', 11)]);

        self::assertNull($this->repository->findTransaction($this->account, 12));
    }

    /**
     * getLastSyncedLedgerIndex() drives the next sync's ledger_index_min.
     *
     * Compared as strings, "999" sorts above "4294967290" and the sync would
     * resume from the wrong point for good. The values sit above any real
     * ledger index because this maximum is global by contract, not scoped to
     * one account.
     */
    public function testLastSyncedLedgerIndexIsANumericMaximum(): void
    {
        $this->repository->saveTransactions([
            $this->transaction('E', 21, [], '999'),
            $this->transaction('F', 22, [], '4294967290'),
        ]);

        self::assertSame('4294967290', $this->repository->getLastSyncedLedgerIndex());
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function transaction(string $seed, int $tag, array $meta = [], string $ledgerIndex = '20180000'): XrplTransaction
    {
        return new XrplTransaction(
            ledgerIndex: $ledgerIndex,
            hash: str_repeat($seed, 63) . '1',
            ctid: 'C133E44700020001',
            account: 'rSenderTest',
            destination: $this->account,
            destinationTag: $tag,
            date: 800000000,
            meta: $meta,
            tx: ['TransactionType' => 'Payment'],
        );
    }

    private function countRows(): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . Installer::TABLE_TX . '`
             WHERE `destination` = "' . pSQL($this->account) . '"',
            false
        );
    }
}
