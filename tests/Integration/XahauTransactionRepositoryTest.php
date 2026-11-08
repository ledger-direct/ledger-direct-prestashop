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

use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopXrplTransactionRepository;

/**
 * The same persistence contract on Xahau's tables, which the Installer creates alongside the
 * XRP Ledger's. Nothing is shared: a row stored here is not in the XRPL table, and the tag
 * counters are kept apart even for one and the same address.
 */
final class XahauTransactionRepositoryTest extends XrplTransactionRepositoryTest
{
    protected const CHAIN = 'XAHAU';
    protected const TABLE_TX = Installer::TABLE_XAHAU_TX;
    protected const TABLE_DESTINATION_TAG = Installer::TABLE_XAHAU_DESTINATION_TAG;

    public function testTheChainsShareNoRowAndNoCounter(): void
    {
        $account = 'rBoth' . bin2hex(random_bytes(8));
        $xahau = new PrestaShopXrplTransactionRepository('XAHAU');
        $xrpl = new PrestaShopXrplTransactionRepository('XRPL');

        try {
            $xahau->saveTransactions([$this->transactionFor($account, 'XAHAU-HASH')]);

            self::assertSame(['XAHAU-HASH'], $xahau->findExistingHashes(['XAHAU-HASH']));
            self::assertSame([], $xrpl->findExistingHashes(['XAHAU-HASH']), 'the XRPL table has no Xahau row');
            self::assertSame([], $xrpl->findTransactions($account, 7));

            $first = $xahau->nextDestinationTagSequence($account);
            self::assertNotSame($first + 1, $xrpl->nextDestinationTagSequence($account), 'a counter of its own per chain');
        } finally {
            foreach ([Installer::TABLE_XAHAU_TX, Installer::TABLE_TX] as $table) {
                \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . $table . '` WHERE `destination` = "' . pSQL($account) . '"');
            }
            foreach ([Installer::TABLE_XAHAU_DESTINATION_TAG, Installer::TABLE_DESTINATION_TAG] as $table) {
                \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . $table . '` WHERE `destination_account` = "' . pSQL($account) . '"');
            }
        }
    }

    private function transactionFor(string $account, string $hash): \Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction
    {
        return new \Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction(
            network: 'testnet',
            ledgerIndex: '20180000',
            hash: $hash,
            ctid: 'C000000000000000',
            account: 'rSender',
            destination: $account,
            destinationTag: 7,
            date: 0,
            meta: [],
            tx: [],
        );
    }
}
