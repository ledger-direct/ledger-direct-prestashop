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

namespace LedgerDirect\Tests\Unit;

use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use LedgerDirect\Admin\OrderPanel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The order panel names a native amount with the chain's own asset: a Xahau
 * order received XAH, not XRP.
 */
final class OrderPanelTransactionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function chains(): iterable
    {
        yield 'XRP Ledger' => ['XRPL', 'XRP'];
        yield 'Xahau' => ['XAHAU', 'XAH'];
    }

    #[DataProvider('chains')]
    public function testANativeAmountCarriesTheChainsAsset(string $chain, string $asset): void
    {
        $row = OrderPanel::presentTransaction($this->transaction(['delivered_amount' => '1500000']), $chain);

        self::assertSame('1.5', $row['delivered']);
        self::assertSame($asset, $row['native_asset']);
    }

    public function testAnIssuedAmountIsNotNamedAsNative(): void
    {
        $row = OrderPanel::presentTransaction($this->transaction(['delivered_amount' => [
            'currency' => '524C555344000000000000000000000000000000',
            'value' => '2.5',
            'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
        ]]), 'XAHAU');

        self::assertSame('2.5', $row['delivered']);
        self::assertNull($row['native_asset']);
    }

    public function testNothingDeliveredHasNoAsset(): void
    {
        $row = OrderPanel::presentTransaction($this->transaction([]), 'XAHAU');

        self::assertNull($row['delivered']);
        self::assertNull($row['native_asset']);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function transaction(array $meta): XrplTransaction
    {
        return new XrplTransaction(
            network: 'testnet',
            ledgerIndex: '20180000',
            hash: str_repeat('A', 64),
            ctid: 'C000000000000001',
            account: 'rSenderTest',
            destination: 'rReceiverTest',
            destinationTag: 77,
            date: 800000000,
            meta: $meta,
            tx: ['TransactionType' => 'Payment'],
        );
    }
}
