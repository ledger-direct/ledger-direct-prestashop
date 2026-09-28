<?php

declare(strict_types=1);

namespace LedgerDirect\Install;

use LedgerDirect\Port\PrestaShopConfigProvider;

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
     * The second state: something arrived on the ledger, but it does not pay
     * the order — too little, or the wrong token. Still open for payment
     * (the sync keeps matching, the payment page keeps rendering), but the
     * merchant can see it in the order list, filter on it, and read it in
     * the history, which "Awaiting XRPL payment" alone would never show.
     */
    public const KEY_ORDER_STATE_INCOMPLETE = 'LEDGERDIRECT_OS_PAYMENT_INCOMPLETE';

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
        return (int) \Configuration::getGlobalValue(self::KEY_ORDER_STATE);
    }

    /** The "XRPL payment incomplete" state id. */
    public static function getIncompleteOrderStateId(): int
    {
        return (int) \Configuration::getGlobalValue(self::KEY_ORDER_STATE_INCOMPLETE);
    }

    /**
     * Every state in which an order is still waiting for money on the ledger
     * and must keep being matched.
     *
     * @return int[]
     */
    public static function openOrderStateIds(): array
    {
        return array_values(array_filter([self::getOrderStateId(), self::getIncompleteOrderStateId()]));
    }

    public static function install(string $moduleName): bool
    {
        // ensureSchema() after createTables(): uninstall keeps the tables, so
        // a reinstall can meet a table created by an older version. That path
        // runs no upgrade script — install() has to bring the schema up to
        // date itself.
        return self::createTables()
            && self::ensureSchema()
            && self::ensureOrderStates($moduleName)
            && self::setDefaultConfiguration()
            && self::ensureCronToken();
    }

    /**
     * Brings an existing `ledger_direct_xrpl_tx` up to the current schema.
     * Idempotent: safe to run on a fresh table, a current one, or one from any
     * earlier version. Called from install() and from every upgrade script.
     *
     * 0.2.0 (core 0.4): the `network` column. A ledger index only means
     * anything within one network, so the sync cursor is scoped by
     * (destination, network) and needs the column plus an index to serve it
     * (INVARIANTS.md, "Tables"). Existing rows are backfilled from their CTID —
     * its last four hex digits are the XRPL network id, 0 mainnet, 1 testnet
     * (XLS-37) — so the cursor keeps working for rows synced before the
     * column existed. Anything else stays '' and simply never feeds a cursor.
     */
    public static function ensureSchema(): bool
    {
        $db = \Db::getInstance();
        $table = _DB_PREFIX_ . self::TABLE_TX;

        if (!self::columnExists($table, 'network')) {
            $added = $db->execute(
                'ALTER TABLE `' . $table . '`
                 ADD COLUMN `network` VARCHAR(16) NOT NULL AFTER `id_ledger_direct_xrpl_tx`'
            );
            if (!$added) {
                return false;
            }
        }

        if (!self::indexExists($table, 'idx_ledger_direct_cursor')) {
            $indexed = $db->execute(
                'ALTER TABLE `' . $table . '`
                 ADD KEY `idx_ledger_direct_cursor` (`destination`, `network`, `ledger_index`)'
            );
            if (!$indexed) {
                return false;
            }
        }

        return $db->execute(
            'UPDATE `' . $table . '`
                SET `network` = CASE RIGHT(`ctid`, 4)
                                    WHEN "0000" THEN "mainnet"
                                    WHEN "0001" THEN "testnet"
                                    ELSE ""
                                END
              WHERE `network` = ""'
        );
    }

    private static function columnExists(string $table, string $column): bool
    {
        $rows = \Db::getInstance()->executeS(
            'SHOW COLUMNS FROM `' . $table . '` LIKE "' . \Db::getInstance()->escape($column) . '"'
        );

        return is_array($rows) && $rows !== [];
    }

    private static function indexExists(string $table, string $index): bool
    {
        $rows = \Db::getInstance()->executeS(
            'SHOW INDEX FROM `' . $table . '` WHERE `Key_name` = "' . \Db::getInstance()->escape($index) . '"'
        );

        return is_array($rows) && $rows !== [];
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
            \Configuration::deleteByName($key);
        }

        return true;
    }

    private static function createTables(): bool
    {
        $engine = _MYSQL_ENGINE_;

        $tx = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::TABLE_TX . '` (
            `id_ledger_direct_xrpl_tx` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `network` VARCHAR(16) NOT NULL,
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
            KEY `idx_ledger_direct_ledger_index` (`ledger_index`),
            KEY `idx_ledger_direct_cursor` (`destination`, `network`, `ledger_index`)
        ) ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4;';

        // One row per destination account, not one per issued tag: the core's
        // DestinationTagService derives the tag from this counter via a fixed
        // bijection, so the table stays constant-size per account. The counter
        // starts at a random offset (see the repository), so INT UNSIGNED has
        // to hold 2^31 plus every tag issued after it — it does, with room.
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

        return \Db::getInstance()->execute($tx)
            && \Db::getInstance()->execute($destinationTag)
            && \Db::getInstance()->execute($orderPaymentIntent)
            && \Db::getInstance()->execute($rateCache);
    }

    /**
     * Creates the module's order states, or reuses the ones a previous install
     * left behind (uninstall keeps the ids on purpose). Public and idempotent:
     * install() and the upgrade scripts both call it.
     */
    public static function ensureOrderStates(string $moduleName): bool
    {
        return self::ensureOrderState(
            self::KEY_ORDER_STATE,
            $moduleName,
            ['de' => 'Warten auf XRPL-Zahlung'],
            'Awaiting XRPL payment',
            '#4169E1'
        ) && self::ensureOrderState(
            self::KEY_ORDER_STATE_INCOMPLETE,
            $moduleName,
            ['de' => 'XRPL-Zahlung unvollständig'],
            'XRPL payment incomplete',
            '#E67E22'
        );
    }

    /**
     * @param array<string, string> $names per language iso code; $fallback for every other language
     */
    private static function ensureOrderState(string $key, string $moduleName, array $names, string $fallback, string $color): bool
    {
        $existingId = (int) \Configuration::getGlobalValue($key);
        if ($existingId > 0) {
            $existing = new \OrderState($existingId);
            if (\Validate::isLoadedObject($existing)) {
                return true;
            }
        }

        $orderState = new \OrderState();
        $orderState->name = [];
        foreach (\Language::getLanguages(false) as $language) {
            $orderState->name[(int) $language['id_lang']] = $names[$language['iso_code']] ?? $fallback;
        }
        $orderState->module_name = $moduleName;
        $orderState->color = $color;
        $orderState->unremovable = true;
        // Not paid, not logable, no invoice: the customer has been shown a
        // payment request, nothing has settled on-chain yet. SyncService moves
        // the order to "Payment accepted" once the ledger covers the amount.
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

        return \Configuration::updateGlobalValue($key, (int) $orderState->id);
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

        return \Configuration::updateGlobalValue(
            PrestaShopConfigProvider::KEY_CRON_TOKEN,
            bin2hex(random_bytes(16))
        );
    }

    private static function setDefaultConfiguration(): bool
    {
        foreach (PrestaShopConfigProvider::defaultConfiguration() as $key => $value) {
            if (!\Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Writes the default for every setting that has none yet and leaves the
     * rest alone — what an upgrade needs when a version adds settings, where
     * setDefaultConfiguration() would reset the merchant's choices.
     */
    public static function ensureConfigurationDefaults(): bool
    {
        foreach (PrestaShopConfigProvider::defaultConfiguration() as $key => $value) {
            if (\Configuration::get($key) !== false) {
                continue;
            }

            if (!\Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        return true;
    }
}
