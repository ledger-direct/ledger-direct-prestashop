<?php

declare(strict_types=1);

namespace LedgerDirect\Presentation;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Brick\Math\BigDecimal;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplAmount;

/**
 * Turns a PaymentIntent into the plain scalars the payment page needs.
 *
 * Keeps three things out of the Smarty layer: the shape difference of the
 * amounts (float for XRP, {currency,value,issuer} for anything else — the
 * known v1 wart in INVARIANTS.md), the QR rendering, and the payment state.
 * The state is the core's PaymentStatus, derived here and never re-derived
 * in a template.
 *
 * Nothing here rounds. Every amount is the core's plain decimal —
 * PaymentIntent::amountRequestedValue(), amountPaidValue() and
 * SettlementPolicy::shortfall() — so the page, the QR code, the wallet
 * module and the poll all show one and the same number. The presenter used
 * to round to five and two places, on the argument that the float carried
 * a binary tail; the core's plain decimal has no tail, and a second rounding
 * in the view is exactly what once left a Shopware order unsettled although
 * the customer had paid what they were told.
 *
 * No PrestaShop dependency on purpose, so this runs in the unit suite.
 */
final class PaymentIntentPresenter
{
    /**
     * How many decimals an exchange rate is shown with. The rate is an
     * average across oracles, so it arrives with the full float tail and is
     * cosmetic beyond a few places.
     */
    private const RATE_DECIMALS = 6;

    private const EXPLORER = [
        'mainnet' => 'https://livenet.xrpl.org/transactions/',
        'testnet' => 'https://testnet.xrpl.org/transactions/',
    ];

    /**
     * @param int|null $now unix timestamp for the state derivation; defaults to the clock
     *
     * @return array<string, mixed>
     */
    public static function present(PaymentIntent $paymentIntent, SettlementPolicy $policy, ?int $now = null): array
    {
        $status = PaymentStatus::fromIntent($paymentIntent, $policy, $now)->toArray();
        $state = (string) $status['state'];

        $amountRequested = $paymentIntent->amountRequestedValue();
        $amountPaid = $paymentIntent->amountPaidValue();
        $shortfall = $policy->shortfall($paymentIntent);
        $isToken = is_array($paymentIntent->amountRequested);

        // The amount to send: the shortfall while a partial payment is in, the request otherwise.
        $amountDue = $state === PaymentStatus::PARTIAL && $shortfall !== null ? $shortfall : $amountRequested;
        $paymentUri = PaymentUri::forIntent($paymentIntent, $amountDue);

        return [
            'base_asset' => $paymentIntent->baseAsset,
            'network' => $paymentIntent->network,
            'is_testnet' => $paymentIntent->network !== 'mainnet',
            'exchange_rate' => self::rate($paymentIntent->exchangeRate),
            'pairing' => $paymentIntent->pairing,
            'quote_currency' => $paymentIntent->quoteCurrency,
            'destination_account' => $paymentIntent->destinationAccount,
            'destination_tag' => $paymentIntent->destinationTag,
            // Display only. The issuer is core-owned and never merchant-editable
            // (INVARIANTS.md, Security); showing it lets a customer verify the
            // trustline they are paying into. The currency code is what a
            // browser wallet needs to build the token payment.
            'issuer' => $isToken ? (string) ($paymentIntent->amountRequested['issuer'] ?? '') : null,
            'currency' => $isToken ? (string) ($paymentIntent->amountRequested['currency'] ?? '') : null,
            'expiry' => $paymentIntent->expiry,
            'state' => $state,
            // Only set while waiting; the core decides whether the quote still
            // stands, and a countdown for a partial payment would be a lie.
            'seconds_left' => $status['seconds_left'],
            'amount' => $amountRequested,
            'amount_paid' => $amountPaid,
            'shortfall' => $shortfall,
            'amount_due' => $amountDue,
            // For a browser wallet: the amount in drops, converted by the core, never in the browser.
            'amount_drops' => $isToken ? null : XrplAmount::xrpToDrops($amountDue),
            // The payment request behind the QR code; see PaymentUri for what it carries.
            'payment_uri' => $paymentUri,
            'explorer_base' => self::EXPLORER[$paymentIntent->network] ?? self::EXPLORER['testnet'],
            'paid_share' => self::paidShare($amountPaid, $amountRequested, $state),
            'hash' => $paymentIntent->hash,
            'qr_data_uri' => self::renderQrCode($paymentUri),
        ];
    }

    /**
     * Whether the quote's validity has passed — regardless of what arrived.
     * Not the same question as `state === 'expired'`: a partial payment on an
     * expired quote is `partial`, but the quote is still expired, and the
     * refresh path needs exactly this fact.
     */
    public static function isExpired(PaymentIntent $paymentIntent): bool
    {
        return $paymentIntent->expiry !== null && $paymentIntent->expiry <= time();
    }

    /**
     * An amount that is not (yet) inside a PaymentIntent — a checkout quote,
     * a delivered amount on the merchant's order panel — as the same plain
     * decimal the intent's own accessors give: the value of an issued
     * currency as quoted, a native amount without exponent or trailing zeros.
     *
     * @param float|array{currency: string, value: string, issuer: string} $amount
     */
    public static function plainAmount(float|array $amount): string
    {
        if (is_array($amount)) {
            return (string) ($amount['value'] ?? '');
        }

        return PaymentIntent::plainDecimal(BigDecimal::of((string) $amount));
    }

    /**
     * A rate as a plain decimal string. PHP renders small floats in exponent
     * notation ("1.0E-5"), which on a payment page reads as a number nobody
     * can send.
     */
    public static function rate(float $rate): string
    {
        $decimal = number_format($rate, self::RATE_DECIMALS, '.', '');

        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }

    /**
     * The progress bar's width in whole per cent — the one computation on the
     * page, and never shown as a number. Only a partial payment has one.
     */
    private static function paidShare(?string $amountPaid, string $amountRequested, string $state): int
    {
        if ($state !== PaymentStatus::PARTIAL || $amountPaid === null || (float) $amountRequested <= 0.0) {
            return 0;
        }

        return (int) max(0, min(100, floor((float) $amountPaid / (float) $amountRequested * 100)));
    }

    /**
     * The QR code the page shows without JavaScript: the same payment
     * request the script renders (PaymentUri), as an inline SVG. With
     * scripts on, qr.js replaces it with its own rendering of the same URI.
     */
    private static function renderQrCode(string $paymentUri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($paymentUri));
    }
}
