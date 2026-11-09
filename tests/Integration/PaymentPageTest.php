<?php

/**
 * LedgerDirect for PrestaShop
 *
 * Copyright (c) 2026 Hardcastle
 * This source file is subject to the MIT license that is bundled with this module in LICENSE.md
 * and available at https://opensource.org/licenses/MIT.
 *
 * @author    Hardcastle <https://www.ledger-direct.com>
 * @copyright 2026 Hardcastle
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopConfigProvider;
use LedgerDirect\Service\PaymentSyncService;
use LedgerDirect\Service\ServiceFactory;
use LedgerDirect\Storage\OrderPaymentIntentRepository;

/**
 * The payment page as a customer's browser receives it — over HTTP, through
 * the real front controller, the real theme and the module's layout hook.
 *
 * What it proves is the markup contract of @ledger-direct/payment-ui: the
 * root attributes the script and the end-to-end harness read, the amount
 * exactly as the core states it, the package's assets, and that the page
 * stands on its own without the theme's header. Rendering through Smarty in
 * isolation would miss the last two.
 */
final class PaymentPageTest extends IntegrationTestCase
{
    private const DESTINATION = 'raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg';
    private const AMOUNT_XRP = 15.06378;
    private const PARTIAL_XRP = 5.0;

    private \Order $order;
    private \Cart $cart;
    private OrderPaymentIntentRepository $intents;
    private string $previousDestination;
    private ?string $seededRateKey = null;

    protected function setUp(): void
    {
        $this->intents = new OrderPaymentIntentRepository();

        $this->previousDestination = (string) \Configuration::get(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT);
        \Configuration::updateValue(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT, self::DESTINATION);

        $this->createAwaitingOrder();
    }

    protected function tearDown(): void
    {
        if ($this->seededRateKey !== null) {
            ServiceFactory::getInstance()->getRateCache()->delete($this->seededRateKey);
        }

        if (isset($this->order) && \Validate::isLoadedObject($this->order)) {
            \Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . Installer::TABLE_ORDER_PAYMENT_INTENT . '`
                 WHERE `id_order` = ' . (int) $this->order->id
            );
            $this->order->delete();
        }

        if (isset($this->cart) && \Validate::isLoadedObject($this->cart)) {
            $this->cart->delete();
        }

        \Configuration::updateValue(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT, $this->previousDestination);
    }

    public function testThePageRendersTheMarkupContractForAWaitingOrder(): void
    {
        [$status, $html] = $this->fetchPage();

        self::assertSame(200, $status);
        self::assertStringContainsString('data-ld-state="waiting"', $html);
        self::assertStringContainsString('data-ld-amount-requested="15.06378"', $html);
        self::assertStringContainsString('data-ld-asset="XRP"', $html);
        self::assertStringContainsString('data-ld-amount-drops="15063780"', $html);
        self::assertStringContainsString('data-ld-account data-value="' . self::DESTINATION . '"', $html);
        self::assertStringContainsString('data-ld-tag data-value="' . $this->destinationTag() . '"', $html);
        self::assertStringContainsString('data-ld-payment-uri="https://xrplf.org//send?to=' . self::DESTINATION . '&amp;dt=' . $this->destinationTag() . '&amp;amount=15.06378"', $html);
        self::assertMatchesRegularExpression('~data-ld-poll-url="[^"]*module/ledgerdirect/poll[^"]*"~', $html);
        self::assertStringContainsString('data-ld-wallets-src="/modules/ledgerdirect/views/js/ledger-direct-payment-ui/wallets.js"', $html);
    }

    public function testThePageShowsTheAmountExactlyAsTheCoreStatesIt(): void
    {
        [, $html] = $this->fetchPage();

        // The visible amount, unrounded and unpadded — the number a customer copies.
        self::assertMatchesRegularExpression('~data-ld-amount>15\.06378<~', $html);
        self::assertStringNotContainsString('15.063780', $html);
    }

    /**
     * With the theme's "combine, compress and cache" on, the module's files
     * are merged into the theme bundles, so the test follows the tags and
     * looks inside: the package CSS scopes everything under .ld-page, the
     * package script looks for [data-ld-page].
     */
    public function testThePageLoadsThePackageAssetsButNotTheWalletLibrary(): void
    {
        [, $html] = $this->fetchPage();

        preg_match_all('~<link[^>]+rel="stylesheet"[^>]+href="([^"]+)"~', $html, $css);
        preg_match_all('~<script[^>]+src="([^"]+)"~', $html, $js);

        self::assertTrue($this->anyAssetContains($css[1], 'payment-page.css', '.ld-page'), 'the package stylesheet is not on the page');
        self::assertTrue($this->anyAssetContains($js[1], 'payment-page.js', 'data-ld-page'), 'the package script is not on the page');
        self::assertDoesNotMatchRegularExpression('~<script[^>]+wallets\.js~', $html);
    }

    /**
     * @param string[] $urls
     */
    private function anyAssetContains(array $urls, string $fileName, string $needle): bool
    {
        foreach ($urls as $url) {
            if (str_contains($url, $fileName)) {
                return true;
            }
        }

        foreach ($urls as $url) {
            if (str_contains($this->fetch($url)[1], $needle)) {
                return true;
            }
        }

        return false;
    }

    public function testThePageStandsOnItsOwnWithoutTheThemesHeader(): void
    {
        [, $html] = $this->fetchPage();

        self::assertStringContainsString('class="ld-page"', $html);
        // The theme's content-only layout keeps empty <header>/<footer> shells;
        // what must be gone is their content — the logo, the cart, the footer blocks.
        self::assertStringContainsString('layout-content-only', $html);
        self::assertStringNotContainsString('_desktop_logo', $html);
        self::assertStringNotContainsString('_desktop_cart', $html);
        self::assertStringNotContainsString('footer-container', $html);
    }

    public function testEverySentenceOfTheStatesIsRenderedWhateverTheState(): void
    {
        [, $html] = $this->fetchPage();

        foreach (['partial', 'wrong_asset', 'expired'] as $state) {
            self::assertStringContainsString('data-ld-block="' . $state . '"', $html);
            self::assertStringContainsString('data-ld-status-for="' . $state . '"', $html);
        }
        self::assertStringContainsString('data-ld-success', $html);
    }

    /**
     * @return array{int, string} HTTP status and body
     */
    public function testAPollThatFindsAnotherRunBookingStillMovesThePageOn(): void
    {
        $this->intents->save((int) $this->order->id, $this->intents->find((int) $this->order->id)->withFulfillment('HASH', self::AMOUNT_XRP, 'CTID'));
        $lock = PaymentSyncService::paymentLockName((int) $this->order->id);
        $otherConnection = new \PDO('mysql:host=' . _DB_SERVER_ . ';dbname=' . _DB_NAME_, _DB_USER_, _DB_PASSWD_);
        $otherConnection->query("SELECT GET_LOCK('{$lock}', 0)");

        try {
            [$status, $body] = $this->fetch(\Context::getContext()->link->getModuleLink(
                'ledgerdirect',
                'poll',
                ['id_order' => (int) $this->order->id, 'key' => $this->order->secure_key],
                true
            ));
        } finally {
            $otherConnection->query("SELECT RELEASE_LOCK('{$lock}')");
        }

        $payload = json_decode($body, true);
        self::assertSame(200, $status);
        self::assertSame('settled', $payload['state']);
        self::assertArrayHasKey('redirect', $payload);
        self::assertTrue(PaymentSyncService::isAwaitingPaymentById((int) $this->order->id), 'the booking is left to the other run');
    }

    private function fetchPage(): array
    {
        return $this->fetch(\Context::getContext()->link->getModuleLink(
            'ledgerdirect',
            'payment',
            ['id_order' => (int) $this->order->id, 'key' => $this->order->secure_key],
            true
        ));
    }

    public function testARefreshBlockedByAnotherRunKeepsThePaymentOnThePage(): void
    {
        $orderId = (int) $this->order->id;
        $this->expireQuoteWithPartialPayment(self::PARTIAL_XRP);
        $before = $this->intents->find($orderId);
        $this->seedExchangeRate();

        $lock = PaymentSyncService::paymentLockName($orderId);
        $otherConnection = new \PDO('mysql:host=' . _DB_SERVER_ . ';dbname=' . _DB_NAME_, _DB_USER_, _DB_PASSWD_);
        $otherConnection->query("SELECT GET_LOCK('{$lock}', 0)");
        $started = microtime(true);

        try {
            [$status, $html] = $this->fetchRefresh();
        } finally {
            $otherConnection->query("SELECT RELEASE_LOCK('{$lock}')");
        }

        self::assertGreaterThanOrEqual(4.9, microtime(true) - $started, 'the refresh waits before giving up');
        self::assertSame(200, $status);
        self::assertStringContainsString('data-ld-state="partial"', $html);
        self::assertSame($before->expiry, $this->intents->find($orderId)?->expiry, 'the quote is unchanged');
        self::assertSame($before->amountPaid, $this->intents->find($orderId)?->amountPaid, 'the payment stays on record');
    }

    public function testARefreshBlockedByAnotherRunLeavesAnUnpaidQuoteExpired(): void
    {
        $orderId = (int) $this->order->id;
        $expired = PaymentIntent::fromArray(['expiry' => time() - 60] + $this->intents->find($orderId)->toArray());
        $this->intents->save($orderId, $expired);
        $this->seedExchangeRate();

        $lock = PaymentSyncService::paymentLockName($orderId);
        $otherConnection = new \PDO('mysql:host=' . _DB_SERVER_ . ';dbname=' . _DB_NAME_, _DB_USER_, _DB_PASSWD_);
        $otherConnection->query("SELECT GET_LOCK('{$lock}', 0)");

        try {
            [, $html] = $this->fetchRefresh();
        } finally {
            $otherConnection->query("SELECT RELEASE_LOCK('{$lock}')");
        }

        self::assertStringContainsString('data-ld-state="expired"', $html);
        self::assertSame($expired->expiry, $this->intents->find($orderId)?->expiry, 'the run holding the order must not be overwritten');
    }

    public function testARefreshCarriesThePaymentOverToTheNewQuote(): void
    {
        $orderId = (int) $this->order->id;
        $this->expireQuoteWithPartialPayment(self::PARTIAL_XRP);
        $this->seedExchangeRate();
        $hash = $this->plantPayment((string) (self::PARTIAL_XRP * 1000000));

        try {
            [$status, $html] = $this->fetchRefresh();
        } finally {
            \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'ledger_direct_xrpl_tx` WHERE `hash` = "' . pSQL($hash) . '"');
        }

        $after = $this->intents->find($orderId);
        self::assertSame(200, $status);
        self::assertStringContainsString('data-ld-state="partial"', $html);
        self::assertGreaterThan(time(), $after->expiry, 'the quote is new');
        self::assertSame($hash, $after->hash);
    }

    private function expireQuoteWithPartialPayment(float $paid): void
    {
        $orderId = (int) $this->order->id;
        $expired = PaymentIntent::fromArray(['expiry' => time() - 60] + $this->intents->find($orderId)->toArray());
        $this->intents->save($orderId, $expired->withFulfillment('PARTIALHASH', $paid, 'C133E44700020001'));
    }

    /**
     * The refresh prices the order through the core; a fresh cached rate keeps the test offline.
     */
    private function seedExchangeRate(): void
    {
        $key = 'ledger-direct.rate.v1.testnet.XRP.' . strtoupper((new \Currency((int) $this->order->id_currency))->iso_code);
        ServiceFactory::getInstance()->getRateCache()->set($key, ['rate' => 2.0, 'fetched_at' => time()], 300);
        $this->seededRateKey = $key;
    }

    private function plantPayment(string $drops): string
    {
        $hash = strtoupper(bin2hex(random_bytes(32)));
        ServiceFactory::getInstance()->getTransactionRepository()->saveTransactions([
            new XrplTransaction(
                network: 'testnet',
                ledgerIndex: '20180000',
                hash: $hash,
                ctid: 'C133E44700020001',
                account: 'rSenderTest',
                destination: self::DESTINATION,
                destinationTag: $this->destinationTag(),
                date: 800000000,
                meta: ['delivered_amount' => $drops],
                tx: ['TransactionType' => 'Payment'],
            ),
        ]);

        return $hash;
    }

    /**
     * @return array{int, string} HTTP status and body
     */
    private function fetchRefresh(): array
    {
        return $this->fetch(\Context::getContext()->link->getModuleLink(
            'ledgerdirect',
            'payment',
            ['id_order' => (int) $this->order->id, 'key' => $this->order->secure_key, 'ld_refresh' => 1],
            true
        ));
    }

    /**
     * @return array{int, string} HTTP status and body
     */
    private function fetch(string $url): array
    {
        // The shop is reached on its own port inside the container, with the
        // Host header of the configured domain so PrestaShop does not
        // redirect to its canonical URL.
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $local = 'http://127.0.0.1' . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Host: {$host}\r\nAccept: text/html\r\n",
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 30,
        ]]);

        $body = (string) file_get_contents($local, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return [$status, $body];
    }

    private function destinationTag(): int
    {
        return (int) $this->intents->find((int) $this->order->id)?->destinationTag;
    }

    private function createAwaitingOrder(): void
    {
        $context = \Context::getContext();

        $this->cart = new \Cart();
        $this->cart->id_customer = self::customerId();
        $this->cart->id_address_delivery = self::addressId();
        $this->cart->id_address_invoice = self::addressId();
        $this->cart->id_lang = (int) $context->language->id;
        $this->cart->id_currency = (int) $context->currency->id;
        $this->cart->id_carrier = 2;
        $this->cart->add();

        $context->cart = $this->cart;
        $this->cart->updateQty(1, (int) \Db::getInstance()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE active = 1 ORDER BY id_product ASC'));
        $this->cart = new \Cart((int) $this->cart->id);
        $context->cart = $this->cart;

        $customer = new \Customer(self::customerId());
        $module = \Module::getInstanceByName('ledgerdirect');
        $total = (float) $this->cart->getOrderTotal(true, \Cart::BOTH);

        $module->validateOrder(
            (int) $this->cart->id,
            Installer::getOrderStateId(),
            $total,
            $module->displayName . ' (XRP)',
            null,
            [],
            (int) $context->currency->id,
            false,
            $customer->secure_key
        );

        $this->order = new \Order((int) $module->currentOrder);

        $this->intents->save((int) $this->order->id, PaymentIntent::quote(
            type: 'xrp-payment',
            chain: 'XRPL',
            network: 'testnet',
            baseAsset: 'XRP',
            quoteCurrency: 'EUR',
            pairing: 'XRP/EUR',
            exchangeRate: 1.27,
            amountRequested: self::AMOUNT_XRP,
            destinationAccount: self::DESTINATION,
            destinationTag: random_int(1000000, 4294967295),
            expiry: time() + 300,
        ));
    }
}
