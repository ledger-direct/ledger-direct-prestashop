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
 * The URL of the wallet library of @ledger-direct/payment-ui
 * (views/js/ledger-direct-payment-ui/wallets.js).
 *
 * The payment page fetches the library by a native import() from
 * data-ld-wallets-src, outside PrestaShop's asset manager, so nothing else
 * versions its URL. It carries the package tag from VERSION, which changes
 * exactly when the copied files do: a browser that cached the library before
 * an update fetches the new one after it. Without a readable VERSION the URL
 * stays plain.
 *
 * No PrestaShop dependency on purpose: the module's URL and directory come in
 * as arguments, so the rule runs in the unit suite.
 */
final class WalletLibrary
{
    private const DIRECTORY = 'views/js/ledger-direct-payment-ui/';

    /**
     * @param string $moduleUri the module's URL path, with a trailing slash (Module::getPathUri())
     * @param string $moduleDir the module's directory, with a trailing slash (Module::getLocalPath())
     */
    public static function url(string $moduleUri, string $moduleDir): string
    {
        $url = $moduleUri . self::DIRECTORY . 'wallets.js';
        $versionFile = $moduleDir . self::DIRECTORY . 'VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '';

        return $version === '' ? $url : $url . '?v=' . rawurlencode($version);
    }
}
