<?php

declare(strict_types=1);

namespace LedgerDirect\Presentation;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;

/**
 * Turns a PaymentIntent into the plain scalars a template needs.
 *
 * Keeps two things out of the Smarty layer: the shape difference of
 * `amount_requested` (float for XRP, {currency,value,issuer} for anything
 * else — the known v1 wart in INVARIANTS.md), and the QR rendering.
 */
final class PaymentIntentPresenter
{
    private const XRP_DECIMALS = 5;
    private const ISSUED_DECIMALS = 2;

    /**
     * @return array<string, mixed>
     */
    public static function present(PaymentIntent $paymentIntent): array
    {
        $expiry = $paymentIntent->expiry;

        return [
            'base_asset' => $paymentIntent->baseAsset,
            'network' => $paymentIntent->network,
            'is_testnet' => $paymentIntent->network !== 'mainnet',
            'amount' => self::formatAmount($paymentIntent),
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
            'expiry' => $expiry,
            'seconds_left' => $expiry === null ? null : max(0, $expiry - time()),
            'is_expired' => self::isExpired($paymentIntent),
            'is_paid' => $paymentIntent->hash !== null,
            'hash' => $paymentIntent->hash,
            'qr_data_uri' => self::renderQrCode($paymentIntent->destinationAccount),
        ];
    }

    public static function isExpired(PaymentIntent $paymentIntent): bool
    {
        return $paymentIntent->expiry !== null && $paymentIntent->expiry <= time();
    }

    /**
     * The requested amount as an exact decimal string.
     *
     * Never printed straight from the float: the core divides through
     * brick/math and hands back e.g. 39.067500000000003 for XRP, which is the
     * binary representation of a value it already rounded to 5 places. Showing
     * that to a customer who is about to type it into a wallet would be
     * actively misleading.
     */
    private static function formatAmount(PaymentIntent $paymentIntent): string
    {
        $amount = $paymentIntent->amountRequested;

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
