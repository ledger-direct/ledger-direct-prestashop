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

use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopConfigProvider;
use LedgerDirect\Service\ServiceFactory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The checkout's last step when no quote can be made: the customer goes back
 * to the checkout with a message, and no order is created.
 *
 * The controller runs in this process, in a subclass that records the
 * redirect with notifications instead of sending it. Each test runs in a
 * process of its own because a plain Tools::redirect() ends the process.
 */
final class ValidationControllerTest extends IntegrationTestCase
{
    private const DESTINATION = 'raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg';

    private \Cart $cart;

    /** @var array<string, string> */
    private array $previousConfig = [];

    protected function setUp(): void
    {
        self::bootPrestaShop();
        require_once dirname(__DIR__, 2) . '/controllers/front/validation.php';

        foreach ([
            PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT => self::DESTINATION,
            PrestaShopConfigProvider::KEY_NETWORK => 'testnet',
            PrestaShopConfigProvider::KEY_ASSET_XRP => '1',
        ] as $key => $value) {
            $this->previousConfig[$key] = (string) \Configuration::get($key);
            \Configuration::updateValue($key, $value);
        }

        $_GET['module'] = 'ledgerdirect';
        $_GET['ld_asset'] = 'XRP';
        $_GET['ld_chain'] = 'XRPL';
    }

    protected function tearDown(): void
    {
        if (isset($this->cart) && \Validate::isLoadedObject($this->cart)) {
            $this->cart->delete();
        }
        self::removeUnpricedCurrency();

        foreach ($this->previousConfig as $key => $value) {
            \Configuration::updateValue($key, $value);
        }
    }

    #[RunInSeparateProcess]
    public function testACheckoutWithoutAPriceGoesBackWithAMessageToTryAgain(): void
    {
        $this->prepareCart(self::unpricedCurrency());

        $redirect = $this->submit();

        self::assertSame('index.php?controller=order&step=1', $redirect['url']);
        self::assertSame(['The price of XRP is temporarily unavailable. Please try again in a moment.'], $redirect['errors']);
        self::assertFalse((bool) \Order::getIdByCartId((int) $this->cart->id), 'no order without a price');
    }

    #[RunInSeparateProcess]
    public function testACheckoutThatFailsForAnotherReasonGoesBackWithAMessage(): void
    {
        $currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $this->prepareCart($currency);
        // A fresh cached rate: the price is there, and the quote fails afterwards, at the destination tag.
        $rateKey = 'ledger-direct.rate.v1.testnet.XRP.' . strtoupper($currency->iso_code);
        ServiceFactory::getInstance()->getRateCache()->set($rateKey, ['rate' => 2.0, 'fetched_at' => time()], 300);
        $table = _DB_PREFIX_ . Installer::TABLE_DESTINATION_TAG;
        \Db::getInstance()->execute("RENAME TABLE `{$table}` TO `{$table}_away`");
        // Also when a plain redirect ends the process before the restore below.
        $restored = false;
        $restore = static function () use ($table, $rateKey, &$restored): void {
            if (!$restored) {
                $restored = true;
                \Db::getInstance()->execute("RENAME TABLE `{$table}_away` TO `{$table}`");
                ServiceFactory::getInstance()->getRateCache()->delete($rateKey);
            }
        };
        register_shutdown_function($restore);

        try {
            $redirect = $this->submit();
        } finally {
            $restore();
        }

        self::assertSame('index.php?controller=order&step=1', $redirect['url']);
        self::assertSame(['Paying with XRP is not possible right now. Please choose another payment method.'], $redirect['errors']);
        self::assertFalse((bool) \Order::getIdByCartId((int) $this->cart->id));
    }

    /**
     * @return array{url: string, errors: list<string>}
     */
    private function submit(): array
    {
        $controller = new class extends \LedgerdirectValidationModuleFrontController {
            /** @var array{url: string, errors: list<string>}|null */
            public ?array $redirected = null;

            public function redirectWithNotifications()
            {
                $this->redirected = ['url' => (string) func_get_arg(0), 'errors' => array_values($this->errors)];

                return null;
            }
        };

        $controller->postProcess();

        self::assertNotNull($controller->redirected, 'the checkout is sent back with notifications');

        return $controller->redirected;
    }

    private function prepareCart(\Currency $currency): void
    {
        $context = \Context::getContext();
        $context->currency = $currency;
        $context->customer = new \Customer(self::customerId());

        $this->cart = new \Cart();
        $this->cart->id_customer = self::customerId();
        $this->cart->id_address_delivery = self::addressId();
        $this->cart->id_address_invoice = self::addressId();
        $this->cart->id_lang = (int) $context->language->id;
        $this->cart->id_currency = (int) $currency->id;
        $this->cart->id_carrier = 2;
        $this->cart->secure_key = $context->customer->secure_key;
        $this->cart->add();
        $context->cart = $this->cart;
        $this->cart->updateQty(1, (int) \Db::getInstance()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE active = 1 ORDER BY id_product ASC'));
        $this->cart = new \Cart((int) $this->cart->id);
        $context->cart = $this->cart;
    }
}
