<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopXrplTransactionRepository;

/**
 * The 0.1.0 -> 0.2.0 schema migration, against the real table.
 *
 * Runs the migration backwards first: drops what 0.2.0 added, so the table
 * looks like a 0.1.0 install with rows in it, then brings it forward again
 * through the same code the upgrade script and install() use. Every step is
 * wrapped so a failing assertion still leaves the table current.
 */
final class InstallerSchemaTest extends IntegrationTestCase
{
    private string $account;
    private string $table;

    protected function setUp(): void
    {
        $this->account = 'rSchema' . bin2hex(random_bytes(8));
        $this->table = _DB_PREFIX_ . Installer::TABLE_TX;
    }

    protected function tearDown(): void
    {
        Installer::ensureSchema();

        \Db::getInstance()->execute(
            'DELETE FROM `' . $this->table . '` WHERE `destination` = "' . pSQL($this->account) . '"'
        );
    }

    public function testEnsureSchemaIsIdempotentOnACurrentTable(): void
    {
        self::assertTrue(Installer::ensureSchema());
        self::assertTrue(Installer::ensureSchema());
        self::assertTrue($this->hasNetworkColumn());
    }

    public function testUpgradeAddsTheNetworkColumnAndBackfillsItFromTheCtid(): void
    {
        $repository = new PrestaShopXrplTransactionRepository();
        $repository->saveTransactions([
            $this->transaction('A', 'C133E44700020001'), // network id 1: testnet
            $this->transaction('B', 'C133E44700020000'), // network id 0: mainnet
            $this->transaction('C', 'C133E44700020002'), // devnet: not one of ours
        ]);

        $this->downgradeToInitialSchema();
        self::assertFalse($this->hasNetworkColumn());

        self::assertTrue(Installer::ensureSchema());
        self::assertTrue($this->hasNetworkColumn());

        $byHash = [];
        foreach ($repository->findTransactions($this->account, 77) as $transaction) {
            $byHash[$transaction->hash[0]] = $transaction->network;
        }
        ksort($byHash);

        self::assertSame(['A' => 'testnet', 'B' => 'mainnet', 'C' => ''], $byHash);
        self::assertSame('20180000', $repository->getLastSyncedLedgerIndex($this->account, 'testnet'));
        self::assertSame('20180000', $repository->getLastSyncedLedgerIndex($this->account, 'mainnet'));
    }

    private function downgradeToInitialSchema(): void
    {
        $db = \Db::getInstance();
        $db->execute('ALTER TABLE `' . $this->table . '` DROP KEY `idx_ledger_direct_cursor`');
        $db->execute('ALTER TABLE `' . $this->table . '` DROP COLUMN `network`');
    }

    private function hasNetworkColumn(): bool
    {
        $rows = \Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $this->table . '` LIKE "network"');

        return is_array($rows) && $rows !== [];
    }

    private function transaction(string $seed, string $ctid): XrplTransaction
    {
        return new XrplTransaction(
            network: 'testnet',
            ledgerIndex: '20180000',
            hash: str_repeat($seed, 63) . '1',
            ctid: $ctid,
            account: 'rSenderTest',
            destination: $this->account,
            destinationTag: 77,
            date: 800000000,
            meta: [],
            tx: ['TransactionType' => 'Payment'],
        );
    }
}
