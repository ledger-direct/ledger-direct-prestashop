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

namespace LedgerDirect\Port;

use Configuration;
use Hardcastle\LedgerDirect\Core\ChainCatalog;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use Hardcastle\LedgerDirect\Core\Presentation\AccentColor;
use LedgerDirect\Chain\SupportedChains;
use LedgerDirect\Presentation\PageLogo;

/**
 * Reads the merchant's LedgerDirect settings out of PrestaShop's
 * `Configuration` store.
 *
 * Note what is *not* here: issuer addresses and currency codes. Those live in
 * the core's StablecoinRegistry and are never merchant-configurable — a wrong
 * issuer sends customer funds to a dead trustline (INVARIANTS.md, "Security").
 * The admin form may display them; it must never write them.
 */
final class PrestaShopConfigProvider implements ConfigProviderInterface
{
    public const CHAIN_XRPL = ChainCatalog::XRPL;
    public const CHAIN_XAHAU = ChainCatalog::XAHAU;

    public const NETWORK_MAINNET = 'mainnet';
    public const NETWORK_TESTNET = 'testnet';

    public const KEY_NETWORK = 'LEDGERDIRECT_NETWORK';
    public const KEY_DESTINATION_ACCOUNT = 'LEDGERDIRECT_DESTINATION_ACCOUNT';
    public const KEY_QUOTE_EXPIRY = 'LEDGERDIRECT_QUOTE_EXPIRY';

    public const KEY_ASSET_XRP = 'LEDGERDIRECT_ASSET_XRP';
    public const KEY_ASSET_RLUSD = 'LEDGERDIRECT_ASSET_RLUSD';
    public const KEY_ASSET_USDC = 'LEDGERDIRECT_ASSET_USDC';

    /** Shared secret for the cron endpoint; generated once at install. */
    public const KEY_CRON_TOKEN = 'LEDGERDIRECT_CRON_TOKEN';

    /**
     * The payment page's look: which logo, an accent colour, and the public
     * identifiers of the wallet apps offered on a phone. All display-only —
     * nothing here decides where money goes.
     */
    public const KEY_PAGE_LOGO_MODE = 'LEDGERDIRECT_PAGE_LOGO_MODE';
    public const KEY_PAGE_LOGO_PATH = 'LEDGERDIRECT_PAGE_LOGO_PATH';
    public const KEY_PAGE_ACCENT = 'LEDGERDIRECT_PAGE_ACCENT';
    public const KEY_XAMAN_API_KEY = 'LEDGERDIRECT_XAMAN_API_KEY';
    public const KEY_WALLETCONNECT_PROJECT_ID = 'LEDGERDIRECT_WALLETCONNECT_PROJECT_ID';

    /**
     * Xahau's keys follow the pattern every chain after the XRP Ledger takes:
     * LEDGERDIRECT_<CHAIN>_NETWORK, one account per network, one switch per asset. The XRPL
     * keys above predate the pattern (one account for both networks) and stay as they are.
     */
    public const KEY_XAHAU_NETWORK = 'LEDGERDIRECT_XAHAU_NETWORK';
    public const KEY_XAHAU_ACCOUNT_MAINNET = 'LEDGERDIRECT_XAHAU_ACCOUNT_MAINNET';
    public const KEY_XAHAU_ACCOUNT_TESTNET = 'LEDGERDIRECT_XAHAU_ACCOUNT_TESTNET';
    public const KEY_XAHAU_ASSET_XAH = 'LEDGERDIRECT_XAHAU_ASSET_XAH';

    /**
     * Five minutes. Long enough for a customer to open their wallet and send,
     * short enough that the shop isn't holding a stale exchange rate. The
     * quote is fixed for this whole window — it lives in the stored
     * PaymentIntent and is never silently recomputed underneath the customer.
     */
    private const DEFAULT_QUOTE_EXPIRY_SECONDS = 300;

    /**
     * Stablecoins default to off — opt-in, because they need a trustline on
     * the merchant's account before a payment can arrive at all.
     *
     * @var array<string, string>
     */
    private const ASSET_KEYS = [
        'XRP' => self::KEY_ASSET_XRP,
        'RLUSD' => self::KEY_ASSET_RLUSD,
        'USDC' => self::KEY_ASSET_USDC,
    ];

    /**
     * A chain the module carries nothing on is answered with its testnet, no account and no
     * asset — never with another chain's settings.
     */
    public function getNetwork(string $chain): string
    {
        if (!SupportedChains::supports($chain)) {
            return self::NETWORK_TESTNET;
        }

        $network = (string) \Configuration::get(self::networkKey($chain));

        // Anything unrecognised falls back to testnet: an unconfigured or
        // corrupted setting must not silently start taking real money.
        return $network === self::NETWORK_MAINNET ? self::NETWORK_MAINNET : self::NETWORK_TESTNET;
    }

    public function getDestinationAccount(string $chain): string
    {
        if (!SupportedChains::supports($chain)) {
            return '';
        }

        return trim((string) \Configuration::get(self::accountKey($chain, $this->getNetwork($chain))));
    }

    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        $key = self::assetKey($chain, $baseAsset);

        return $key !== null && (bool) \Configuration::get($key);
    }

    /** The key of a chain's network setting. */
    public static function networkKey(string $chain): string
    {
        return $chain === self::CHAIN_XRPL ? self::KEY_NETWORK : 'LEDGERDIRECT_' . strtoupper($chain) . '_NETWORK';
    }

    /**
     * The key of a chain's receiving account on a network. The XRP Ledger has one account for
     * both networks; every other chain one per network.
     */
    public static function accountKey(string $chain, string $network): string
    {
        return $chain === self::CHAIN_XRPL
            ? self::KEY_DESTINATION_ACCOUNT
            : 'LEDGERDIRECT_' . strtoupper($chain) . '_ACCOUNT_' . strtoupper($network);
    }

    /** The key of an asset's switch, or null for an asset the module does not offer. */
    public static function assetKey(string $chain, string $asset): ?string
    {
        $asset = strtoupper($asset);
        if (!SupportedChains::offers($chain, $asset)) {
            return null;
        }

        return $chain === self::CHAIN_XRPL
            ? self::ASSET_KEYS[$asset]
            : 'LEDGERDIRECT_' . strtoupper($chain) . '_ASSET_' . $asset;
    }

    public function getQuoteExpirySeconds(): int
    {
        $expiry = (int) \Configuration::get(self::KEY_QUOTE_EXPIRY);

        return $expiry > 0 ? $expiry : self::DEFAULT_QUOTE_EXPIRY_SECONDS;
    }

    /** `shop`, `custom` or `none` — see PageLogo; anything else reads as `shop`. */
    public function getPageLogoMode(): string
    {
        $mode = (string) \Configuration::get(self::KEY_PAGE_LOGO_MODE);

        return in_array($mode, PageLogo::MODES, true) ? $mode : PageLogo::MODE_SHOP;
    }

    /** A path below img/, as the merchant typed it; PageLogo decides whether it is usable. */
    public function getPageLogoPath(): string
    {
        return trim((string) \Configuration::get(self::KEY_PAGE_LOGO_PATH));
    }

    /** The stored accent colour; AccentColor::sanitize() decides whether the page uses it. */
    public function getPageAccentColor(): string
    {
        $stored = \Configuration::get(self::KEY_PAGE_ACCENT);

        return is_string($stored) && $stored !== '' ? $stored : AccentColor::DEFAULT;
    }

    /** Xaman's public API key; empty means the "open in wallet app" button is not offered. */
    public function getXamanApiKey(): string
    {
        return trim((string) \Configuration::get(self::KEY_XAMAN_API_KEY));
    }

    /** The WalletConnect project id; empty means it is not offered. */
    public function getWalletConnectProjectId(): string
    {
        return trim((string) \Configuration::get(self::KEY_WALLETCONNECT_PROJECT_ID));
    }

    /**
     * @return array<string, scalar>
     */
    public static function defaultConfiguration(): array
    {
        return [
            self::KEY_NETWORK => self::NETWORK_TESTNET,
            self::KEY_DESTINATION_ACCOUNT => '',
            self::KEY_QUOTE_EXPIRY => self::DEFAULT_QUOTE_EXPIRY_SECONDS,
            self::KEY_ASSET_XRP => true,
            self::KEY_ASSET_RLUSD => false,
            self::KEY_ASSET_USDC => false,
            self::KEY_XAHAU_NETWORK => self::NETWORK_TESTNET,
            self::KEY_XAHAU_ACCOUNT_MAINNET => '',
            self::KEY_XAHAU_ACCOUNT_TESTNET => '',
            self::KEY_XAHAU_ASSET_XAH => false,
            self::KEY_PAGE_LOGO_MODE => PageLogo::MODE_SHOP,
            self::KEY_PAGE_LOGO_PATH => '',
            self::KEY_PAGE_ACCENT => AccentColor::DEFAULT,
            self::KEY_XAMAN_API_KEY => '',
            self::KEY_WALLETCONNECT_PROJECT_ID => '',
        ];
    }

    /**
     * The merchant-facing settings, i.e. exactly what uninstall() clears.
     *
     * The cron token is deliberately not among them: it is generated once and
     * survives a reinstall, so the cron URL a merchant pasted into their
     * hosting panel keeps working after a module reset. Same reasoning as the
     * order-state id.
     *
     * @return string[]
     */
    public static function configurationKeys(): array
    {
        return array_keys(self::defaultConfiguration());
    }

    /** The cron endpoint's shared secret. Empty means "not generated yet". */
    public static function getCronToken(): string
    {
        return (string) \Configuration::getGlobalValue(self::KEY_CRON_TOKEN);
    }
}
