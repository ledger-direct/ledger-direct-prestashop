<?php

declare(strict_types=1);

namespace LedgerDirect\Port;

use Configuration;
use Hardcastle\LedgerDirect\Core\Port\ConfigProviderInterface;
use LedgerDirect\Presentation\AccentColor;
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
    public const CHAIN_XRPL = 'XRPL';

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

    public function getNetwork(string $chain): string
    {
        self::assertXrpl($chain);

        $network = (string) \Configuration::get(self::KEY_NETWORK);

        // Anything unrecognised falls back to testnet: an unconfigured or
        // corrupted setting must not silently start taking real money.
        return $network === self::NETWORK_MAINNET ? self::NETWORK_MAINNET : self::NETWORK_TESTNET;
    }

    public function getDestinationAccount(string $chain): string
    {
        self::assertXrpl($chain);

        return trim((string) \Configuration::get(self::KEY_DESTINATION_ACCOUNT));
    }

    public function isAssetEnabled(string $chain, string $baseAsset): bool
    {
        if ($chain !== self::CHAIN_XRPL) {
            return false;
        }

        $key = self::ASSET_KEYS[$baseAsset] ?? null;
        if ($key === null) {
            return false;
        }

        return (bool) \Configuration::get($key);
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

    private static function assertXrpl(string $chain): void
    {
        if ($chain !== self::CHAIN_XRPL) {
            throw new \InvalidArgumentException("LedgerDirect has no configuration for chain '{$chain}'; only '" . self::CHAIN_XRPL . "' is supported.");
        }
    }
}
