<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Unit;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplAmount;
use LedgerDirect\Service\AmountMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rule that decides whether an order is paid. Worth testing hardest:
 * a false negative strands a customer who paid, a false positive ships goods
 * for free.
 */
final class AmountMatcherTest extends TestCase
{
    private const ISSUER = 'rMxCKbEDwqr76QuheSUMdEGf4B9xJ8m5De';

    /**
     * The regression this class exists for.
     *
     * The core divides through brick/math and hands back a float; 15.06378
     * cannot be represented exactly in binary, so the stored
     * `amount_requested` carries noise. The customer sends exactly the five
     * decimals they were shown, which arrives as an exact integer number of
     * drops. Compared raw, the correct payment loses.
     */
    public function testExactPaymentSettlesDespiteFloatNoise(): void
    {
        $intent = self::xrpIntent(15.063780000000001);
        $delivered = XrplAmount::dropsToXrp('15063780');

        self::assertSame(AmountMatcher::RESULT_SETTLED, AmountMatcher::evaluate($intent, $delivered));
    }

    public function testOverpaymentSettles(): void
    {
        $intent = self::xrpIntent(15.06378);

        self::assertSame(
            AmountMatcher::RESULT_SETTLED,
            AmountMatcher::evaluate($intent, XrplAmount::dropsToXrp('20000000'))
        );
    }

    public function testUnderpaymentDoesNotSettle(): void
    {
        $intent = self::xrpIntent(15.06378);

        self::assertSame(
            AmountMatcher::RESULT_UNDERPAID,
            AmountMatcher::evaluate($intent, XrplAmount::dropsToXrp('15063770'))
        );
    }

    /**
     * The boundary is exact, in the merchant's favour.
     *
     * The delivered amount is rounded *down* before comparing, so a single
     * drop short does not settle. That asymmetry is deliberate: rounding the
     * customer's payment up would credit money that never arrived, and a
     * wallet sending the displayed five decimals lands on the exact figure
     * anyway.
     */
    public function testOneDropShortDoesNotSettle(): void
    {
        $intent = self::xrpIntent(15.06378);

        self::assertSame(
            AmountMatcher::RESULT_SETTLED,
            AmountMatcher::evaluate($intent, XrplAmount::dropsToXrp('15063780'))
        );
        self::assertSame(
            AmountMatcher::RESULT_UNDERPAID,
            AmountMatcher::evaluate($intent, XrplAmount::dropsToXrp('15063779'))
        );
    }

    public function testIssuedCurrencyDoesNotSettleAnXrpOrder(): void
    {
        $intent = self::xrpIntent(15.06378);
        $delivered = ['currency' => 'USD', 'value' => '9999', 'issuer' => self::ISSUER];

        self::assertSame(AmountMatcher::RESULT_WRONG_ASSET, AmountMatcher::evaluate($intent, $delivered));
    }

    public function testXrpDoesNotSettleAStablecoinOrder(): void
    {
        $intent = self::rlusdIntent('19.12');

        self::assertSame(AmountMatcher::RESULT_WRONG_ASSET, AmountMatcher::evaluate($intent, 9999.0));
    }

    /**
     * The attack this guards against: destination tags are public and
     * guessable, so without an issuer check anyone could settle an RLUSD
     * order by sending a worthless token that reuses the currency code.
     */
    public function testSameCurrencyFromAnotherIssuerDoesNotSettle(): void
    {
        $intent = self::rlusdIntent('19.12');
        $delivered = [
            'currency' => '524C555344000000000000000000000000000000',
            'value' => '19.12',
            'issuer' => 'rQhWct2fv4Vc4KRjRgMrxa8xPN9Zx9iLKV',
        ];

        self::assertSame(AmountMatcher::RESULT_WRONG_ASSET, AmountMatcher::evaluate($intent, $delivered));
    }

    public function testWrongCurrencyCodeFromTheRightIssuerDoesNotSettle(): void
    {
        $intent = self::rlusdIntent('19.12');
        $delivered = [
            'currency' => '5553444300000000000000000000000000000000',
            'value' => '19.12',
            'issuer' => self::ISSUER,
        ];

        self::assertSame(AmountMatcher::RESULT_WRONG_ASSET, AmountMatcher::evaluate($intent, $delivered));
    }

    #[DataProvider('stablecoinAmounts')]
    public function testStablecoinAmountsCompareAtTwoDecimals(string $delivered, string $expected): void
    {
        $intent = self::rlusdIntent('19.12');

        self::assertSame($expected, AmountMatcher::evaluate($intent, [
            'currency' => '524C555344000000000000000000000000000000',
            'value' => $delivered,
            'issuer' => self::ISSUER,
        ]));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function stablecoinAmounts(): array
    {
        return [
            'exact' => ['19.12', AmountMatcher::RESULT_SETTLED],
            'trailing zeros' => ['19.120000', AmountMatcher::RESULT_SETTLED],
            'more' => ['20', AmountMatcher::RESULT_SETTLED],
            'a cent short' => ['19.11', AmountMatcher::RESULT_UNDERPAID],
            'far short' => ['1', AmountMatcher::RESULT_UNDERPAID],
        ];
    }

    private static function xrpIntent(float $amountRequested): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 1.27,
            amountRequested: $amountRequested,
            destinationAccount: 'rngEbP1Lmig46X6BWjKLSQDHUrPzoy8tyA',
            destinationTag: 114729,
            expiry: null,
        );
    }

    private static function rlusdIntent(string $value): PaymentIntent
    {
        return PaymentIntent::quote(
            type: 'rlusd-payment',
            chain: 'XRPL',
            network: 'mainnet',
            baseAsset: 'RLUSD',
            quoteCurrency: 'USD',
            pairing: 'RLUSD/USD',
            exchangeRate: 1.0,
            amountRequested: [
                'currency' => '524C555344000000000000000000000000000000',
                'value' => $value,
                'issuer' => self::ISSUER,
            ],
            destinationAccount: 'rngEbP1Lmig46X6BWjKLSQDHUrPzoy8tyA',
            destinationTag: 114729,
            expiry: null,
        );
    }
}
