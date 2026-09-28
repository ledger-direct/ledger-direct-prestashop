<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Unit;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Payment\SettlementPolicy;
use LedgerDirect\Presentation\PaymentIntentPresenter;
use PHPUnit\Framework\TestCase;

/**
 * What the template gets to see, per payment state and per amount shape.
 *
 * The numbers matter most: `amount`, `amount_paid`, `shortfall` and
 * `amount_due` are what a customer types into a wallet, so they must be the
 * core's plain decimals — no float tail, and no second rounding either: the
 * page, the QR code and the wallet module all carry the same string.
 */
final class PaymentIntentPresenterTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const ISSUER = 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV';

    private SettlementPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SettlementPolicy();
    }

    public function testAFreshQuoteIsWaitingWithACountdownAndNoPaymentFields(): void
    {
        $view = $this->present(self::xrpIntent(expiry: self::NOW + 120));

        self::assertSame('waiting', $view['state']);
        self::assertSame(120, $view['seconds_left']);
        self::assertNull($view['amount_paid']);
        // Nothing counted yet, so the whole request is still due — the core's shortfall, not null.
        self::assertSame('15.06378', $view['shortfall']);
        self::assertSame('15.06378', $view['amount_due']);
        self::assertSame(0, $view['paid_share']);
        self::assertArrayNotHasKey('is_paid', $view);
        self::assertArrayNotHasKey('is_expired', $view);
    }

    public function testTheRequestedAmountIsTheCoresPlainDecimalNotARoundedOne(): void
    {
        // 39.0675 as a float is 39.067500000000003 — the core's known v1 wart,
        // which amountRequestedValue() already resolves. No zeros are padded on.
        $xrp = $this->present(self::xrpIntent(amount: 39.0675));
        $usdc = $this->present(self::usdcIntent(value: '0.8'));

        self::assertSame('39.0675', $xrp['amount']);
        self::assertSame('0.8', $usdc['amount']);
        self::assertSame('39.0675', $xrp['amount_due']);
        self::assertSame('39067500', $xrp['amount_drops']);
        self::assertNull($usdc['amount_drops']);
    }

    public function testTheExchangeRateIsAPlainDecimalWithAtMostSixPlaces(): void
    {
        self::assertSame('1.27', $this->present(self::xrpIntent())['exchange_rate']);
        self::assertSame('0.00001', PaymentIntentPresenter::rate(0.00001));
        self::assertSame('1.201763', PaymentIntentPresenter::rate(1.2017633333333));
    }

    public function testThePageKnowsTheExplorerOfTheNetwork(): void
    {
        self::assertSame('https://testnet.xrpl.org/transactions/', $this->present(self::xrpIntent())['explorer_base']);
    }

    public function testAnExpiredQuoteWithNothingPaidIsExpired(): void
    {
        $view = $this->present(self::xrpIntent(expiry: self::NOW - 1));

        self::assertSame('expired', $view['state']);
        self::assertNull($view['seconds_left']);
    }

    public function testAnUnderpaymentIsPartialWithBothNumbersFormatted(): void
    {
        $intent = self::xrpIntent(amount: 15.06378)->withFulfillment('AA', 5.0, 'C1');

        $view = $this->present($intent);

        self::assertSame('partial', $view['state']);
        self::assertSame('5', $view['amount_paid']);
        self::assertSame('10.06378', $view['shortfall']);
        // The amount to send, the drops and the QR request all follow the shortfall.
        self::assertSame('10.06378', $view['amount_due']);
        self::assertSame('10063780', $view['amount_drops']);
        self::assertStringContainsString('&amount=10.06378', $view['payment_uri']);
        self::assertSame(33, $view['paid_share']);
        self::assertNull($view['seconds_left']);
        self::assertSame('AA', $view['hash']);
    }

    /**
     * The quote is expired *and* partly paid: the customer's money comes
     * first. The template's refresh path asks isExpired() separately.
     */
    public function testAPartialPaymentOnAnExpiredQuoteIsStillPartial(): void
    {
        $intent = self::xrpIntent(expiry: self::NOW - 1)->withFulfillment('AA', 1.0, 'C1');

        self::assertSame('partial', $this->present($intent)['state']);
        self::assertTrue(PaymentIntentPresenter::isExpired($intent));
    }

    public function testATokenFromAnotherIssuerIsWrongAssetWithTheFullAmountStillDue(): void
    {
        $intent = self::usdcIntent(value: '12.50')->withFulfillment(
            'BB',
            ['currency' => 'USD', 'value' => '12.50', 'issuer' => 'rSomebodyElse'],
            'C2'
        );

        $view = $this->present($intent);

        self::assertSame('wrong_asset', $view['state']);
        // The delivered value as the ledger states it; the shortfall as the core computes it.
        self::assertSame('12.50', $view['amount_paid']);
        self::assertSame('12.5', $view['shortfall']);
        // Not a partial payment, so the amount to send stays the request as quoted.
        self::assertSame('12.50', $view['amount_due']);
        self::assertSame(0, $view['paid_share']);
    }

    public function testAnIssuedCurrencyShortfallIsThePlainDecimalOfTheDifference(): void
    {
        $intent = self::usdcIntent(value: '12.50')->withFulfillment(
            'BB',
            ['currency' => 'USD', 'value' => '4.2', 'issuer' => self::ISSUER],
            'C2'
        );

        $view = $this->present($intent);

        self::assertSame('partial', $view['state']);
        self::assertSame('4.2', $view['amount_paid']);
        self::assertSame('8.3', $view['shortfall']);
    }

    public function testAFullPaymentIsSettled(): void
    {
        $intent = self::xrpIntent(amount: 15.06378)->withFulfillment('AA', 15.06378, 'C1');

        $view = $this->present($intent);

        self::assertSame('settled', $view['state']);
        self::assertSame('15.06378', $view['amount_paid']);
        self::assertNull($view['shortfall']);
    }

    public function testTheIssuerAndCurrencyAreShownForATokenAndNotForXrp(): void
    {
        $usdc = $this->present(self::usdcIntent());

        self::assertSame(self::ISSUER, $usdc['issuer']);
        self::assertSame('USD', $usdc['currency']);
        self::assertNull($this->present(self::xrpIntent())['issuer']);
        self::assertNull($this->present(self::xrpIntent())['currency']);
    }

    /**
     * The request behind the QR code: account, tag and the amount to send —
     * for a token also currency and issuer — as PaymentUri specifies it.
     */
    public function testThePaymentRequestCarriesAccountTagAndAmount(): void
    {
        $xrp = $this->present(self::xrpIntent(amount: 15.06378));
        $usdc = $this->present(self::usdcIntent(value: '12.50'));

        self::assertSame('https://xrplf.org//send?to=raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg&dt=123456&amount=15.06378', $xrp['payment_uri']);
        self::assertSame('https://xrplf.org//send?to=raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg&dt=123456&amount=12.50&currency=USD&issuer=' . self::ISSUER, $usdc['payment_uri']);
    }

    public function testTheQrCodeIsAnInlineSvgOfThePaymentRequest(): void
    {
        $view = $this->present(self::xrpIntent());

        self::assertStringStartsWith('data:image/svg+xml;base64,', $view['qr_data_uri']);
        self::assertStringContainsString('<svg', base64_decode(substr($view['qr_data_uri'], 26), true) ?: '');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PaymentIntent $intent): array
    {
        return PaymentIntentPresenter::present($intent, $this->policy, self::NOW);
    }

    private static function xrpIntent(float $amount = 15.06378, ?int $expiry = self::NOW + 300): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 1.27,
            amountRequested: $amount,
            destinationAccount: 'raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg',
            destinationTag: 123456,
            expiry: $expiry,
        );
    }

    private static function usdcIntent(string $value = '12.50', ?int $expiry = self::NOW + 300): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'usdc-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'USDC',
            quoteCurrency: 'EUR',
            pairing: 'USDC/EUR',
            exchangeRate: 0.92,
            amountRequested: ['currency' => 'USD', 'value' => $value, 'issuer' => self::ISSUER],
            destinationAccount: 'raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg',
            destinationTag: 123456,
            expiry: $expiry,
        );
    }
}
