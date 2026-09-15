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
 * The numbers matter most: `amount`, `amount_paid` and `shortfall` are what a
 * customer types into a wallet, so they must come out as exact decimal
 * strings on the asset's scale — never as a float with a binary tail.
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
        self::assertNull($view['shortfall']);
        self::assertArrayNotHasKey('is_paid', $view);
        self::assertArrayNotHasKey('is_expired', $view);
    }

    public function testTheRequestedAmountIsPrintedOnTheAssetsScale(): void
    {
        // 39.0675 as a float is 39.067500000000003 — the core's known v1 wart.
        $xrp = $this->present(self::xrpIntent(amount: 39.0675));
        $usdc = $this->present(self::usdcIntent(value: '0.8'));

        self::assertSame('39.06750', $xrp['amount']);
        self::assertSame('0.80', $usdc['amount']);
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
        self::assertSame('5.00000', $view['amount_paid']);
        self::assertSame('10.06378', $view['shortfall']);
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
        self::assertSame('12.50', $view['amount_paid']);
        self::assertSame('12.50', $view['shortfall']);
    }

    public function testAnIssuedCurrencyShortfallIsPrintedWithTwoPlaces(): void
    {
        $intent = self::usdcIntent(value: '12.50')->withFulfillment(
            'BB',
            ['currency' => 'USD', 'value' => '4.2', 'issuer' => self::ISSUER],
            'C2'
        );

        $view = $this->present($intent);

        self::assertSame('partial', $view['state']);
        self::assertSame('4.20', $view['amount_paid']);
        self::assertSame('8.30', $view['shortfall']);
    }

    public function testAFullPaymentIsSettled(): void
    {
        $intent = self::xrpIntent(amount: 15.06378)->withFulfillment('AA', 15.06378, 'C1');

        $view = $this->present($intent);

        self::assertSame('settled', $view['state']);
        self::assertSame('15.06378', $view['amount_paid']);
        self::assertNull($view['shortfall']);
    }

    public function testTheIssuerIsShownForATokenAndNotForXrp(): void
    {
        self::assertSame(self::ISSUER, $this->present(self::usdcIntent())['issuer']);
        self::assertNull($this->present(self::xrpIntent())['issuer']);
    }

    public function testTheQrCodeIsAnInlineSvgOfTheDestinationAccount(): void
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
