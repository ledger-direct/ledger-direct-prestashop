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

use LedgerDirect\Presentation\Explorer;
use PHPUnit\Framework\TestCase;

final class ExplorerTest extends TestCase
{
    public function testEachChainAndNetworkHasItsExplorer(): void
    {
        self::assertSame('https://livenet.xrpl.org/transactions/H', Explorer::transactionUrl('XRPL', 'mainnet', 'H'));
        self::assertSame('https://testnet.xrpl.org/transactions/H', Explorer::transactionUrl('XRPL', 'testnet', 'H'));
        self::assertSame('https://explorer.xahau.network/tx/H', Explorer::transactionUrl('XAHAU', 'mainnet', 'H'));
        self::assertSame('https://explorer.xahau-test.net/tx/H', Explorer::transactionUrl('XAHAU', 'testnet', 'H'));
    }

    public function testNoLinkWithoutAHashOrAnExplorer(): void
    {
        self::assertNull(Explorer::transactionUrl('XRPL', 'testnet', null));
        self::assertNull(Explorer::transactionUrl('XRPL', 'testnet', ''));
        self::assertNull(Explorer::transactionUrl('STELLAR', 'testnet', 'H'));
        self::assertSame('', Explorer::transactionBase('XRPL', 'devnet'));
    }
}
