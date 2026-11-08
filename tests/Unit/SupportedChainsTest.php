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

use Hardcastle\LedgerDirect\Core\ChainCatalog;
use LedgerDirect\Chain\SupportedChains;
use LedgerDirect\Port\PrestaShopConfigProvider;
use PHPUnit\Framework\TestCase;

/**
 * The completeness rule behind the payment options: every chain the core knows is either
 * carried, with a configuration key and a checkout logo for each of its assets, or stands in
 * PENDING_CHAINS. A new chain in the core turns this red until the module decides.
 */
final class SupportedChainsTest extends TestCase
{
    public function testEveryCatalogueChainIsCarriedOrExplicitlyPending(): void
    {
        foreach (ChainCatalog::chains() as $chain) {
            $pending = in_array($chain->chain, SupportedChains::PENDING_CHAINS, true);
            self::assertSame(!$pending, SupportedChains::supports($chain->chain), $chain->chain);

            foreach ($chain->assets as $asset) {
                $key = PrestaShopConfigProvider::assetKey($chain->chain, $asset->code);
                if ($pending) {
                    self::assertNull($key, "{$chain->chain} is pending but {$asset->code} has a switch");
                    continue;
                }

                self::assertNotNull($key, "{$chain->chain} {$asset->code} has no switch");
                self::assertContains($key, PrestaShopConfigProvider::configurationKeys(), "$key has no default");
                self::assertFileExists(dirname(__DIR__, 2) . '/views/img/' . strtolower($asset->code) . '_payment.svg', 'checkout logo');
                self::assertSame([$chain, $asset], SupportedChains::chainAndAssetFor($asset->code), 'the asset names its chain');
            }
        }
    }

    public function testTheXrplKeysAreTheOnesFromBeforeAndXahauFollowsThePattern(): void
    {
        self::assertSame(['XRPL', 'XAHAU'], SupportedChains::chains());
        self::assertSame('LEDGERDIRECT_ASSET_RLUSD', PrestaShopConfigProvider::assetKey('XRPL', 'rlusd'));
        self::assertSame('LEDGERDIRECT_NETWORK', PrestaShopConfigProvider::networkKey('XRPL'));
        self::assertSame('LEDGERDIRECT_DESTINATION_ACCOUNT', PrestaShopConfigProvider::accountKey('XRPL', 'mainnet'));
        self::assertSame('LEDGERDIRECT_XAHAU_ASSET_XAH', PrestaShopConfigProvider::assetKey('XAHAU', 'XAH'));
        self::assertSame('LEDGERDIRECT_XAHAU_NETWORK', PrestaShopConfigProvider::networkKey('XAHAU'));
        self::assertSame('LEDGERDIRECT_XAHAU_ACCOUNT_TESTNET', PrestaShopConfigProvider::accountKey('XAHAU', 'testnet'));
    }

    public function testNothingIsOfferedOutsideTheCatalogue(): void
    {
        self::assertNull(PrestaShopConfigProvider::assetKey('XRPL', 'XAH'), 'XAH is not an XRPL asset');
        self::assertNull(PrestaShopConfigProvider::assetKey('XAHAU', 'RLUSD'));
        self::assertNull(PrestaShopConfigProvider::assetKey('STELLAR', 'XLM'), 'pending');
        self::assertNull(PrestaShopConfigProvider::assetKey('DOGE', 'DOGE'));
        self::assertFalse(SupportedChains::offers('STELLAR', 'XLM'));
        self::assertNull(SupportedChains::chainAndAssetFor('XLM'));
    }
}
