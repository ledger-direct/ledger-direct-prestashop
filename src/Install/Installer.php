<?php

declare(strict_types=1);

namespace LedgerDirect\Install;

use Configuration;
use Db;
use Language;
use LedgerDirect\Port\PrestaShopConfigProvider;
use OrderState;
use Validate;

/**
 * Schema and order-state setup for install()/uninstall().
 *
 * The core deliberately ships no DDL — it knows only the logical table names
 * (`ledger_direct_xrpl_tx`, `ledger_direct_xrpl_destination_tag`) and never
 * builds SQL. Prepending `_DB_PREFIX_` and creating the tables is entirely
 * this adapter's job (INVARIANTS.md, "Tables").
 */
final class Installer
{
    public const TABLE_TX = 'ledger_direct_xrpl_tx';
    public const TABLE_DESTINATION_TAG = 'ledger_direct_xrpl_destination_tag';

    /**
     * Adapter-owned, not one of the core's two tables — PrestaShop has no
     * generic order-metadata store, so the serialized PaymentIntent needs a
     * home. Named `ledger_direct_order_*` rather than
     * `ledger_direct_{chain}_{entity}` so the distinction stays visible.
     */
    public const TABLE_ORDER_PAYMENT_INTENT = 'ledger_direct_order_payment_intent';

    /**
     * PSR-16 store for the core's exchange-rate cache. Adapter-owned, and the
     * one table here that is genuinely disposable: every row can be refetched
     * from an oracle.
     */
    public const TABLE_RATE_CACHE = 'ledger_direct_rate_cache';

    public const KEY_ORDER_STATE = 'LEDGERDIRECT_OS_AWAITING_PAYMENT';

    /**
     * The "awaiting XRPL payment" state id.
     *
     * Lives here rather than on the module class because the module class is
     * not autoloaded — PrestaShop loads it by path via Module::getInstance*().
     * Anything reachable outside a front controller (the sync service, a CLI
     * script) would blow up on a bare `Ledgerdirect::` reference.
     */
    public static function getOrderStateId(): int
    {
        return (int) Configuration::getGlobalValue(self::KEY_ORDER_STATE);
    }

    public static function install(string $moduleName): bool
    {
        return self::createTables()
            && self::ensureOrderState($moduleName)
            && self::setDefaultConfiguration()
            && self::ensureCronToken();
    }

    /**
     * Deliberately keeps both tables. `ledger_direct_xrpl_tx` is a re-syncable
     * cache, but `ledger_direct_xrpl_destination_tag` is not: it holds the
     * per-account counter that guarantees a destination tag is never issued
     * twice. Dropping it would restart the sequence at 0 and hand a fresh
     * order a tag an old payment already used — which is exactly how an order
     * gets matched against someone else's transaction. The order state is kept
     * for the same reason PrestaShop keeps states around: existing orders
     * reference it in their history.
     */
    public static function uninstall(): bool
    {
        // Merchant-facing settings go (PrestaShop convention); the order-state
        // id stays so a reinstall reuses the state instead of duplicating it.
        foreach (PrestaShopConfigProvider::configurationKeys() as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    private static function createTables(): bool
    {
        $engine = _MYSQL_ENGINE_;

        $tx = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_TX . '` (
            `id_ledger_direct_xrpl_tx` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ledger_index` BIGINT UNSIGNED NOT NULL,
            `hash` VARCHAR(64) NOT NULL,
            `ctid` VARCHAR(16) NOT NULL,
            `account` VARCHAR(64) NOT NULL,
            `destination` VARCHAR(64) NOT NULL,
            `destination_tag` INT UNSIGNED NULL DEFAULT NULL,
            `date` INT UNSIGNED NOT NULL,
            `meta` LONGTEXT NOT NULL,
            `tx` LONGTEXT NOT NULL,
            PRIMARY KEY (`id_ledger_direct_xrpl_tx`),
            UNIQUE KEY `uniq_ledger_direct_hash` (`hash`),
            KEY `idx_ledger_direct_destination` (`destination`, `destination_tag`),
            KEY `idx_ledger_direct_ledger_index` (`ledger_index`)
        ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;';

        // One row per destination account, not one per issued tag: the core's
        // DestinationTagService derives the tag from this counter via a fixed
        // bijection, so the table stays constant-size per account.
        $destinationTag = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_DESTINATION_TAG . '` (
            `destination_account` VARCHAR(64) NOT NULL,
            `sequence` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`destination_account`)
        ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;';

        $orderPaymentIntent = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_ORDER_PAYMENT_INTENT . '` (
            `id_order` INT UNSIGNED NOT NULL,
            `payment_intent` LONGTEXT NOT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_order`)
        ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;';

        // One row per (network, asset, quote currency) — three rows for a shop
        // accepting all three assets in one currency. It does not grow.
        $rateCache = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_RATE_CACHE . '` (
            `cache_key` VARCHAR(191) NOT NULL,
            `value` LONGTEXT NOT NULL,
            `expires_at` INT UNSIGNED NULL DEFAULT NULL,
            PRIMARY KEY (`cache_key`),
            KEY `idx_ledger_direct_rate_expires` (`expires_at`)
        ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($tx)
            && Db::getInstance()->execute($destinationTag)
            && Db::getInstance()->execute($orderPaymentIntent)
            && Db::getInstance()->execute($rateCache);
    }

    /**
     * Creates the "awaiting XRPL payment" state, or reuses the one a previous
     * install left behind (uninstall keeps the id on purpose).
     */
    private static function ensureOrderState(string $moduleName): bool
    {
        $existingId = (int) Configuration::getGlobalValue(self::KEY_ORDER_STATE);
        if ($existingId > 0) {
            $existing = new OrderState($existingId);
            if (Validate::isLoadedObject($existing)) {
                return true;
            }
        }

        $orderState = new OrderState();
        $orderState->name = [];
        foreach (Language::getLanguages(false) as $language) {
            $orderState->name[(int) $language['id_lang']] = $language['iso_code'] === 'de'
                ? 'Warten auf XRPL-Zahlung'
                : 'Awaiting XRPL payment';
        }
        $orderState->module_name = $moduleName;
        $orderState->color = '#4169E1';
        $orderState->unremovable = true;
        // Not paid, not logable, no invoice: the customer has been shown a
        // payment request, nothing has settled on-chain yet. SyncService moves
        // the order to "Payment accepted" once a transaction matches.
        $orderState->logable = false;
        $orderState->paid = false;
        $orderState->invoice = false;
        $orderState->send_email = false;
        $orderState->hidden = false;
        $orderState->delivery = false;
        $orderState->shipped = false;

        if (!$orderState->add()) {
            return false;
        }

        return Configuration::updateGlobalValue(self::KEY_ORDER_STATE, (int) $orderState->id);
    }

    /**
     * The cron endpoint is reachable without a session, so a shared secret is
     * the only thing standing in front of it. Generated once and kept across
     * reinstalls so a merchant's configured cron URL doesn't quietly stop
     * working after a module reset.
     */
    private static function ensureCronToken(): bool
    {
        if (PrestaShopConfigProvider::getCronToken() !== '') {
            return true;
        }

        return Configuration::updateGlobalValue(
            PrestaShopConfigProvider::KEY_CRON_TOKEN,
            bin2hex(random_bytes(16))
        );
    }

    private static function setDefaultConfiguration(): bool
    {
        foreach (PrestaShopConfigProvider::defaultConfiguration() as $key => $value) {
            if (!Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        return true;
    }
}
