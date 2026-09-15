<?php

declare(strict_types=1);

namespace LedgerDirect\Presentation;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;

/**
 * Turns a PaymentIntent into the plain scalars a template needs.
 *
 * Keeps three things out of the Smarty layer: the shape difference of the
 * amounts (float for XRP, {currency,value,issuer} for anything else — the
 * known v1 wart in INVARIANTS.md), the QR rendering, and the payment state.
 * The state is the core's PaymentStatus, derived here and never re-derived
 * in a template: `hash !== null` used to stand in for "paid", and after a
 * payment in the wrong token it would have been true and wrong.
 *
 * No PrestaShop dependency on purpose, so this runs in the unit suite.
 */
final class PaymentIntentPresenter
{
    private const XRP_DECIMALS = 5;
    private const ISSUED_DECIMALS = 2;

    /**
     * @param int|null $now unix timestamp for the state derivation; defaults to the clock
     *
     * @return array<string, mixed>
     */
    public static function present(PaymentIntent $paymentIntent, SettlementPolicy $policy, ?int $now = null): array
    {
        $status = PaymentStatus::fromIntent($paymentIntent, $policy, $now)->toArray();

        return [
            'base_asset' => $paymentIntent->baseAsset,
            'network' => $paymentIntent->network,
            'is_testnet' => $paymentIntent->network !== 'mainnet',
            'amount' => self::formatAmount($paymentIntent->amountRequested),
            'exchange_rate' => $paymentIntent->exchangeRate,
            'pairing' => $paymentIntent->pairing,
            'quote_currency' => $paymentIntent->quoteCurrency,
            'destination_account' => $paymentIntent->destinationAccount,
            'destination_tag' => $paymentIntent->destinationTag,
            // Display only. The issuer is core-owned and never merchant-editable
            // (INVARIANTS.md, Security); showing it lets a customer verify the
            // trustline they are paying into.
            'issuer' => is_array($paymentIntent->amountRequested)
                ? ($paymentIntent->amountRequested['issuer'] ?? null)
                : null,
            'expiry' => $paymentIntent->expiry,
            'state' => $status['state'],
            // Only set while waiting; the core decides whether the quote still
            // stands, and a countdown for a partial payment would be a lie.
            'seconds_left' => $status['seconds_left'],
            // Formatted through the same rule as the request. Never raw: the
            // shortfall is a number the customer types into a wallet, and a
            // float tail there is the rounding story Shopware already had once.
            'amount_paid' => $status['amount_paid'] === null ? null : self::formatAmount($status['amount_paid']),
            'shortfall' => $status['shortfall'] === null ? null : self::formatAmount($status['shortfall']),
            'hash' => $paymentIntent->hash,
            'qr_data_uri' => self::renderQrCode($paymentIntent->destinationAccount),
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
     * An amount as an exact decimal string, whatever its shape.
     *
     * Never printed straight from the float: the core divides through
     * brick/math and hands back e.g. 39.067500000000003 for XRP, which is the
     * binary representation of a value it already rounded to 5 places. Showing
     * that to a customer who is about to type it into a wallet would be
     * actively misleading.
     *
     * @param float|array{currency: string, value: string, issuer: string} $amount
     */
    private static function formatAmount(float|array $amount): string
    {
        if (is_array($amount)) {
            return number_format((float) ($amount['value'] ?? 0), self::ISSUED_DECIMALS, '.', '');
        }

        return number_format($amount, self::XRP_DECIMALS, '.', '');
    }

    /**
     * QR payload is the bare destination account.
     *
     * Deliberately not a `ripple:`/`xrpl:` URI with amount and tag baked in:
     * wallet support for those is inconsistent, and a wallet that fails to
     * parse the URI would fall back to *something* rather than nothing. Every
     * XRPL wallet can scan a classic r-address, and the tag and amount are
     * shown next to the code as copyable fields. The proper fix later is an
     * X-address (XLS-5d), which encodes account and destination tag in one
     * scannable value so the tag cannot be dropped by accident.
     */
    private static function renderQrCode(string $destinationAccount): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,' . base64_encode($writer->writeString($destinationAccount));
    }
}
