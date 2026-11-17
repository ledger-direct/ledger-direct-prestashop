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

use LedgerDirect\Port\PrestaShopConfigProvider;

/**
 * A shop set to mainnet reads mainnet and the mainnet account, for each chain — the branch
 * the other tests, which all run on the testnet, never enter (Documentation/Mainnet-Testing.md,
 * K1). Anything unrecognised stays on the testnet.
 */
class ConfigProviderNetworkTest extends IntegrationTestCase
{
    private const KEYS = [
        PrestaShopConfigProvider::KEY_NETWORK,
        PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT,
        PrestaShopConfigProvider::KEY_XAHAU_NETWORK,
        PrestaShopConfigProvider::KEY_XAHAU_ACCOUNT_MAINNET,
        PrestaShopConfigProvider::KEY_XAHAU_ACCOUNT_TESTNET,
    ];

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $key) {
            $this->previous[$key] = \Configuration::get($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            \Configuration::updateValue($key, (string) $value);
        }
    }

    public function testAnXrplShopOnMainnetReadsMainnetAndItsAccount(): void
    {
        \Configuration::updateValue(PrestaShopConfigProvider::KEY_NETWORK, 'mainnet');
        \Configuration::updateValue(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT, 'rMainnetMerchant');

        $config = new PrestaShopConfigProvider();

        self::assertSame('mainnet', $config->getNetwork('XRPL'));
        self::assertSame('rMainnetMerchant', $config->getDestinationAccount('XRPL'));
    }

    public function testAXahauShopOnMainnetReadsTheMainnetAccountNotTheTestnetOne(): void
    {
        \Configuration::updateValue(PrestaShopConfigProvider::KEY_XAHAU_NETWORK, 'mainnet');
        \Configuration::updateValue(PrestaShopConfigProvider::KEY_XAHAU_ACCOUNT_MAINNET, 'rXahauMainnet');
        \Configuration::updateValue(PrestaShopConfigProvider::KEY_XAHAU_ACCOUNT_TESTNET, 'rXahauTestnet');

        $config = new PrestaShopConfigProvider();

        self::assertSame('mainnet', $config->getNetwork('XAHAU'));
        self::assertSame('rXahauMainnet', $config->getDestinationAccount('XAHAU'));
    }

    public function testAnUnrecognisedNetworkStaysOnTheTestnet(): void
    {
        \Configuration::updateValue(PrestaShopConfigProvider::KEY_NETWORK, 'Mainnet ');

        self::assertSame('testnet', (new PrestaShopConfigProvider())->getNetwork('XRPL'));
    }
}
