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

use LedgerDirect\Presentation\WalletLibrary;
use PHPUnit\Framework\TestCase;

/**
 * The page fetches the wallet library by import(), outside PrestaShop's asset
 * manager; its URL names the package version so a cached copy does not outlive
 * an update.
 */
final class WalletLibraryTest extends TestCase
{
    public function testTheUrlCarriesThePackageVersionTheFilesWereCopiedFrom(): void
    {
        $moduleDir = dirname(__DIR__, 2) . '/';
        $version = trim((string) file_get_contents($moduleDir . 'views/js/ledger-direct-payment-ui/VERSION'));

        self::assertSame(
            '/modules/ledgerdirect/views/js/ledger-direct-payment-ui/wallets.js?v=' . $version,
            WalletLibrary::url('/modules/ledgerdirect/', $moduleDir)
        );
    }

    public function testWithoutAVersionFileTheUrlStaysPlain(): void
    {
        self::assertSame(
            '/modules/ledgerdirect/views/js/ledger-direct-payment-ui/wallets.js',
            WalletLibrary::url('/modules/ledgerdirect/', sys_get_temp_dir() . '/ledgerdirect-no-such-module/')
        );
    }
}
