<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopConfigProvider;
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

    private \Order $order;
    private \Cart $cart;
    private OrderPaymentIntentRepository $intents;
    private string $previousDestination;

    protected function setUp(): void
    {
        $this->intents = new OrderPaymentIntentRepository();

        $this->previousDestination = (string) \Configuration::get(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT);
        \Configuration::updateValue(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT, self::DESTINATION);

        $this->createAwaitingOrder();
    }

    protected function tearDown(): void
    {
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
    private function fetchPage(): array
    {
        return $this->fetch(\Context::getContext()->link->getModuleLink(
            'ledgerdirect',
            'payment',
            ['id_order' => (int) $this->order->id, 'key' => $this->order->secure_key],
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
