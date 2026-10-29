<?php

/**
 * LedgerDirect for PrestaShop
 *
 * Copyright (c) 2026 Hardcastle
 * This source file is subject to the MIT license that is bundled with this module in LICENSE.md
 * and available at https://opensource.org/licenses/MIT.
 *
 * @author    Hardcastle <https://www.ledger-direct.com>
 * @copyright 2026 Hardcastle
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopXrplTransactionRepository;

/**
 * The schema migrations since 0.1.0, against the real table.
 *
 * Runs them backwards first: drops what 0.2.0 added and undoes the 0.5.2
 * column rename, so the table
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
        self::assertSame(['tx_ctid'], $this->ctidColumns());
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
        self::assertSame(['ctid'], $this->ctidColumns());

        self::assertTrue(Installer::ensureSchema());
        self::assertTrue($this->hasNetworkColumn());
        self::assertSame(['tx_ctid'], $this->ctidColumns());

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
        $db->execute('ALTER TABLE `' . $this->table . '` CHANGE COLUMN `tx_ctid` `ctid` VARCHAR(16) NOT NULL');
    }

    /**
     * @return string[] the CTID column(s) the table carries, old name and new
     */
    private function ctidColumns(): array
    {
        $rows = \Db::getInstance()->executeS(
            'SHOW COLUMNS FROM `' . $this->table . '` WHERE `Field` IN ("ctid", "tx_ctid")'
        );

        return is_array($rows) ? array_column($rows, 'Field') : [];
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
