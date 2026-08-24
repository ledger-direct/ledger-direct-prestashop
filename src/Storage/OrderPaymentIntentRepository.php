<?php

declare(strict_types=1);

namespace LedgerDirect\Storage;

use Db;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use InvalidArgumentException;
use JsonException;
use LedgerDirect\Install\Installer;
use RuntimeException;

/**
 * Stores one serialized PaymentIntent per order.
 *
 * This is adapter-owned storage, not a core port: INVARIANTS.md is explicit
 * that "the storage key a platform wraps the record in is that platform's
 * concern". PrestaShop has no generic order-metadata table, so the module
 * brings its own — hence the `ledger_direct_order_*` name, deliberately
 * outside the core's `ledger_direct_{chain}_{entity}` convention, so nobody
 * mistakes it for a table the core knows about.
 *
 * What is stored is the record's serialized form verbatim, `schema_version`
 * included. Reading goes back through PaymentIntent::fromArray(), so a record
 * written by a future schema version is rejected loudly rather than silently
 * misread.
 */
final class OrderPaymentIntentRepository
{
    public function save(int $orderId, PaymentIntent $paymentIntent): void
    {
        $json = self::encode($paymentIntent->toArray());

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . Installer::TABLE_ORDER_PAYMENT_INTENT . '`
                    (`id_order`, `payment_intent`, `date_add`, `date_upd`)
                VALUES (' . $orderId . ', "' . self::esc($json) . '", NOW(), NOW())
                ON DUPLICATE KEY UPDATE `payment_intent` = VALUES(`payment_intent`), `date_upd` = NOW()';

        if (!Db::getInstance()->execute($sql)) {
            throw new RuntimeException("LedgerDirect: could not store the payment intent for order {$orderId}.");
        }
    }

    public function find(int $orderId): ?PaymentIntent
    {
        $json = Db::getInstance()->getValue(
            'SELECT `payment_intent` FROM `' . _DB_PREFIX_ . Installer::TABLE_ORDER_PAYMENT_INTENT . '`
             WHERE `id_order` = ' . $orderId,
            false
        );

        if ($json === false || $json === null || $json === '') {
            return null;
        }

        try {
            $data = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "LedgerDirect: the stored payment intent for order {$orderId} is not valid JSON.",
                0,
                $exception
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException("LedgerDirect: the stored payment intent for order {$orderId} is malformed.");
        }

        try {
            return PaymentIntent::fromArray($data);
        } catch (InvalidArgumentException $exception) {
            // Wrong/unknown schema_version lands here. Surfacing it beats
            // returning null: a silently ignored record would look to the rest
            // of the module like "this order was never paid for".
            throw new RuntimeException(
                "LedgerDirect: the stored payment intent for order {$orderId} is not readable: "
                . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'LedgerDirect: could not serialise the payment intent: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }

    /** SQL escaping only — see PrestaShopXrplTransactionRepository::esc(). */
    private static function esc(string $value): string
    {
        return Db::getInstance()->escape($value, true, false);
    }
}
