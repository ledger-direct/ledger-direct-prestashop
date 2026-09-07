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
     * The port's contract: a random start in [0, 2^31 - 1] on the first call
     * for an account, then strictly increasing by one. Never 0 by design — a
     * fixed start would make every installation issue the same tags.
     */
    public function testDestinationTagSequenceStartsAtARandomOffsetAndIncreases(): void
    {
        $first = $this->repository->nextDestinationTagSequence($this->account);

        self::assertGreaterThanOrEqual(0, $first);
        self::assertLessThanOrEqual(2 ** 31 - 1, $first);
        self::assertSame($first + 1, $this->repository->nextDestinationTagSequence($this->account));
        self::assertSame($first + 2, $this->repository->nextDestinationTagSequence($this->account));
    }

    public function testDestinationTagSequenceIsPerAccount(): void
    {
        $first = $this->repository->nextDestinationTagSequence($this->account);
        $this->repository->nextDestinationTagSequence($this->account);

        $other = $this->account . 'X';

        try {
            $otherFirst = $this->repository->nextDestinationTagSequence($other);

            // Its own counter: not a continuation of the first account's, and
            // the next call for the first account is unaffected by it.
            self::assertNotSame($first + 2, $otherFirst);
            self::assertSame($first + 2, $this->repository->nextDestinationTagSequence($this->account));
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

        $found = $this->repository->findTransactions($this->account, 4242);

        self::assertCount(1, $found);
        self::assertSame($meta, $found[0]->meta);
        self::assertSame('testnet', $found[0]->network);
    }

    /**
     * Newest first, and all of them: which row pays an order is the core's
     * decision, so the repository must not pre-select.
     */
    public function testTransactionsOnATagComeBackNewestFirst(): void
    {
        $this->repository->saveTransactions([
            $this->transaction('A', 55, [], '20180001'),
            $this->transaction('B', 55, [], '20180003'),
            $this->transaction('C', 55, [], '20180002'),
        ]);

        $found = $this->repository->findTransactions($this->account, 55);

        self::assertSame(['20180003', '20180002', '20180001'], array_map(
            static fn (XrplTransaction $transaction): string => $transaction->ledgerIndex,
            $found
        ));
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

        self::assertSame([], $this->repository->findTransactions($this->account, 12));
    }

    /**
     * getLastSyncedLedgerIndex() drives the next sync's ledger_index_min.
     *
     * Compared as strings, "999" sorts above "4294967290" and the sync would
     * resume from the wrong point for good.
     */
    public function testLastSyncedLedgerIndexIsANumericMaximum(): void
    {
        $this->repository->saveTransactions([
            $this->transaction('E', 21, [], '999'),
            $this->transaction('F', 22, [], '4294967290'),
        ]);

        self::assertSame('4294967290', $this->repository->getLastSyncedLedgerIndex($this->account, 'testnet'));
    }

    /**
     * The cursor is scoped by network and account. A mainnet row (index far
     * above anything the testnet will reach) must not pin the testnet cursor,
     * and another account's rows must not feed this one's.
     */
    public function testLastSyncedLedgerIndexIsScopedByNetworkAndAccount(): void
    {
        $this->repository->saveTransactions([
            $this->transaction('G', 31, [], '20180000', 'testnet'),
            $this->transaction('H', 32, [], '99000000', 'mainnet'),
        ]);

        self::assertSame('20180000', $this->repository->getLastSyncedLedgerIndex($this->account, 'testnet'));
        self::assertSame('99000000', $this->repository->getLastSyncedLedgerIndex($this->account, 'mainnet'));
        self::assertNull($this->repository->getLastSyncedLedgerIndex($this->account . 'X', 'testnet'));
        self::assertNull($this->repository->getLastSyncedLedgerIndex($this->account, 'devnet'));
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function transaction(string $seed, int $tag, array $meta = [], string $ledgerIndex = '20180000', string $network = 'testnet'): XrplTransaction
    {
        return new XrplTransaction(
            network: $network,
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
