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

namespace LedgerDirect\Presentation;

/**
 * Where a transaction hash can be looked up, per chain and network.
 *
 * The core knows no explorers; this is the module's mapping, the same one the Shopware and
 * Magento plugins carry. The payment page and the Back Office order panel build their links
 * from it.
 */
final class Explorer
{
    private const TRANSACTIONS = [
        'XRPL' => [
            'mainnet' => 'https://livenet.xrpl.org/transactions/',
            'testnet' => 'https://testnet.xrpl.org/transactions/',
        ],
        'XAHAU' => [
            'mainnet' => 'https://explorer.xahau.network/tx/',
            'testnet' => 'https://explorer.xahau-test.net/tx/',
        ],
    ];

    /** The URL prefix a hash is appended to; '' for a chain or network without an explorer here. */
    public static function transactionBase(string $chain, string $network): string
    {
        return self::TRANSACTIONS[$chain][$network] ?? '';
    }

    public static function transactionUrl(string $chain, string $network, ?string $hash): ?string
    {
        $base = self::transactionBase($chain, $network);

        return $base === '' || $hash === null || $hash === '' ? null : $base . $hash;
    }
}
