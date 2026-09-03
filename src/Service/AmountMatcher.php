<?php

declare(strict_types=1);

namespace LedgerDirect\Service;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;

/**
 * Decides whether what arrived on the ledger settles what was requested.
 *
 * Two separate questions, both of which must be yes: is this the *right
 * asset*, and is it *enough*.
 */
final class AmountMatcher
{
    /**
     * The scale the customer was actually shown — the core rounds XRP to 5
     * decimals and issued currencies to 2 (INVARIANTS.md, "Conversion &
     * rounding"), and the payment page prints exactly that.
     */
    private const DISPLAY_SCALE_XRP = 5;
    private const DISPLAY_SCALE_ISSUED = 2;

    public const RESULT_SETTLED = 'settled';
    public const RESULT_UNDERPAID = 'underpaid';
    public const RESULT_WRONG_ASSET = 'wrong_asset';

    /**
     * @param float|array<string, mixed> $delivered what the ledger delivered,
     *                                              already decoded by the core (XrplTransaction::getDeliveredAmount())
     *
     * @return self::RESULT_*
     */
    public static function evaluate(PaymentIntent $paymentIntent, float|array $delivered): string
    {
        $requested = $paymentIntent->amountRequested;

        // Shape mismatch means someone sent XRP where a token was requested or
        // vice versa. Never credit that: the destination tag alone is not proof
        // the right thing arrived.
        if (is_array($requested) !== is_array($delivered)) {
            return self::RESULT_WRONG_ASSET;
        }

        if (is_array($requested)) {
            // A destination tag is public and guessable. Without checking the
            // issuer, anyone could settle an RLUSD order by sending a
            // worthless self-issued token that happens to use the same
            // currency code.
            if (
                ($delivered['currency'] ?? null) !== $requested['currency']
                || ($delivered['issuer'] ?? null) !== $requested['issuer']
            ) {
                return self::RESULT_WRONG_ASSET;
            }

            return self::compare(
                (string) $delivered['value'],
                (string) $requested['value'],
                self::DISPLAY_SCALE_ISSUED
            );
        }

        return self::compare((string) $delivered, (string) $requested, self::DISPLAY_SCALE_XRP);
    }

    /**
     * @return self::RESULT_SETTLED|self::RESULT_UNDERPAID
     */
    private static function compare(string $delivered, string $requested, int $displayScale): string
    {
        // Both sides are rounded to the scale the customer saw before being
        // compared. The stored `amount_requested` is a float that still
        // carries binary noise — 15.06378 round-trips through JSON as
        // 15.063780000000001 — while the delivered amount is exact, decoded
        // from an integer number of drops. Comparing those two raw would
        // reject a payment of precisely the requested amount, which is the
        // one case that must always succeed.
        $deliveredAtScale = BigDecimal::of($delivered)->toScale($displayScale, RoundingMode::DOWN);
        $requestedAtScale = BigDecimal::of($requested)->toScale($displayScale, RoundingMode::HALF_UP);

        return $deliveredAtScale->isGreaterThanOrEqualTo($requestedAtScale)
            ? self::RESULT_SETTLED
            : self::RESULT_UNDERPAID;
    }
}
