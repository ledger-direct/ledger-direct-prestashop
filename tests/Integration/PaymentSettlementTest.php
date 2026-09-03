<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Integration;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplAmount;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopConfigProvider;
use LedgerDirect\Service\PaymentSyncService;
use LedgerDirect\Service\ServiceFactory;
use LedgerDirect\Storage\OrderPaymentIntentRepository;
use Order;

/**
 * Settling a real order against a transaction on the ledger.
 *
 * Every assertion here corresponds to something that was once wrong: the
 * order that reached the paid state while the code reported failure, and the
 * duplicate payment record that left an order looking paid twice.
 *
 * Deliberately offline. The intent is built directly rather than through
 * PaymentIntentService so no oracle is called: pricing is the core's
 * responsibility and has its own tests, and a suite that fails when an
 * exchange API is slow teaches nobody anything.
 */
final class PaymentSettlementTest extends IntegrationTestCase
{
    private const DESTINATION = 'raXkRCAYkqaoFYCeVej93SzCTtiAbbRzAg';
    private const AMOUNT_XRP = 15.06378;
    private const AMOUNT_DROPS = '15063780';

    private \Order $order;
    private \Cart $cart;
    private int $destinationTag;
    private OrderPaymentIntentRepository $intents;

    /** @var string[] every hash this test planted, so tearDown can remove them all. */
    private array $plantedHashes = [];
    private string $previousDestination;

    protected function setUp(): void
    {
        $this->intents = new OrderPaymentIntentRepository();

        $this->previousDestination = (string) \Configuration::get(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT);
        \Configuration::updateValue(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT, self::DESTINATION);

        $this->destinationTag = random_int(1000000, 4294967295);
        $this->createAwaitingOrder();
    }

    protected function tearDown(): void
    {
        $db = \Db::getInstance();

        foreach ($this->plantedHashes as $hash) {
            $db->execute(
                'DELETE FROM `' . _DB_PREFIX_ . Installer::TABLE_TX . '`
                 WHERE `hash` = "' . pSQL($hash) . '"'
            );
        }
        $this->plantedHashes = [];

        if (isset($this->order) && \Validate::isLoadedObject($this->order)) {
            $db->execute(
                'DELETE FROM `' . _DB_PREFIX_ . Installer::TABLE_ORDER_PAYMENT_INTENT . '`
                 WHERE `id_order` = ' . (int) $this->order->id
            );
            $this->order->delete();
        }

        if (isset($this->cart) && \Validate::isLoadedObject($this->cart)) {
            $this->cart->delete();
        }

        \Configuration::updateValue(
            PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT,
            $this->previousDestination
        );
    }

    public function testOrderStartsAwaitingPayment(): void
    {
        self::assertSame(Installer::getOrderStateId(), $this->currentState());
        self::assertSame(0.0, (float) $this->order->total_paid_real);
    }

    public function testNoTransactionLeavesTheOrderAlone(): void
    {
        self::assertFalse($this->sync()->matchOrder((int) $this->order->id));
        self::assertSame(Installer::getOrderStateId(), $this->currentState());
    }

    /**
     * An EscrowCreate or CheckCreate carries a destination and a tag too, and
     * lands in the same table. Nothing was delivered, so nothing is owed.
     */
    public function testTransactionThatDeliveredNothingDoesNotSettle(): void
    {
        $this->plant([]);

        self::assertFalse($this->sync()->matchOrder((int) $this->order->id));
        self::assertSame(Installer::getOrderStateId(), $this->currentState());
    }

    /**
     * The ledger's marker for a pre-2014 partial payment whose amount it
     * cannot reconstruct. Money arrived but the figure is unknowable, so this
     * is a case for a human, not a guess.
     */
    public function testUnavailableDeliveredAmountDoesNotSettle(): void
    {
        $this->plant(['delivered_amount' => 'unavailable']);

        self::assertFalse($this->sync()->matchOrder((int) $this->order->id));
        self::assertSame(Installer::getOrderStateId(), $this->currentState());
    }

    public function testUnderpaymentDoesNotSettle(): void
    {
        $this->plant(['delivered_amount' => (string) ((int) self::AMOUNT_DROPS - 1000000)]);

        self::assertFalse($this->sync()->matchOrder((int) $this->order->id));
        self::assertSame(Installer::getOrderStateId(), $this->currentState());
    }

    /**
     * The core's SettlementPolicy tolerates 0.15 % on the native asset (a quote is a float
     * rounded to five places, wallets may shave rounding). A single drop short therefore
     * settles now — the previous local matcher rejected it. Both are defensible; what matters
     * is that every LedgerDirect plugin decides the same way, so the rule lives in the core.
     */
    public function testAPaymentWithinTheCoreToleranceSettles(): void
    {
        $this->plant(['delivered_amount' => (string) ((int) self::AMOUNT_DROPS - 1)]);

        self::assertTrue($this->sync()->matchOrder((int) $this->order->id));
        self::assertSame((int) \Configuration::get('PS_OS_PAYMENT'), $this->currentState());
    }

    public function testPaymentUnderAnotherDestinationTagDoesNotSettle(): void
    {
        $this->plant(['delivered_amount' => self::AMOUNT_DROPS], $this->destinationTag + 1);

        self::assertFalse($this->sync()->matchOrder((int) $this->order->id));
        self::assertSame(Installer::getOrderStateId(), $this->currentState());
    }

    public function testExactPaymentSettlesTheOrder(): void
    {
        $hash = $this->plant(['delivered_amount' => self::AMOUNT_DROPS]);

        self::assertTrue($this->sync()->matchOrder((int) $this->order->id));
        self::assertSame((int) \Configuration::get('PS_OS_PAYMENT'), $this->currentState());

        $intent = $this->intents->find((int) $this->order->id);
        self::assertSame($hash, $intent?->hash);
        self::assertSame(XrplAmount::dropsToXrp(self::AMOUNT_DROPS), $intent?->amountPaid);
    }

    /**
     * The regression that mattered most. Moving an order into a paid state
     * makes PrestaShop write its own OrderPayment for the invoice's
     * outstanding amount. Writing one ourselves as well left the order booked
     * twice — total_paid_real at double the order total.
     */
    public function testSettlingBooksThePaymentExactlyOnce(): void
    {
        $hash = $this->plant(['delivered_amount' => self::AMOUNT_DROPS]);
        $this->sync()->matchOrder((int) $this->order->id);

        $order = new \Order((int) $this->order->id);
        $payments = $order->getOrderPayments();

        self::assertCount(1, $payments);
        self::assertSame($hash, $payments[0]->transaction_id);
        self::assertSame(
            (float) $order->total_paid,
            (float) $order->total_paid_real,
            'The order must be booked as paid exactly once.'
        );
    }

    public function testASecondRunDoesNotCreditAgain(): void
    {
        $this->plant(['delivered_amount' => self::AMOUNT_DROPS]);

        self::assertTrue($this->sync()->matchOrder((int) $this->order->id));
        self::assertFalse($this->sync()->matchOrder((int) $this->order->id));

        $order = new \Order((int) $this->order->id);
        self::assertCount(1, $order->getOrderPayments());
        self::assertSame((float) $order->total_paid, (float) $order->total_paid_real);
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return string the transaction hash
     */
    private function plant(array $meta, ?int $tag = null): string
    {
        $hash = strtoupper(bin2hex(random_bytes(32)));
        $this->plantedHashes[] = $hash;

        ServiceFactory::getInstance()->getTransactionRepository()->saveTransactions([
            new XrplTransaction(
                ledgerIndex: '20180000',
                hash: $hash,
                ctid: 'C133E44700020001',
                account: 'rSenderTest',
                destination: self::DESTINATION,
                destinationTag: $tag ?? $this->destinationTag,
                date: 800000000,
                meta: $meta,
                tx: ['TransactionType' => 'Payment'],
            ),
        ]);

        return $hash;
    }

    private function sync(): PaymentSyncService
    {
        // matchOrder() on purpose, not syncAndMatchOrder(): the sync half
        // would reach out to a live XRPL node.
        return PaymentSyncService::create();
    }

    /** Read past the query cache — an ObjectModel reload can be stale here. */
    private function currentState(): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `current_state` FROM `' . _DB_PREFIX_ . 'orders`
             WHERE `id_order` = ' . (int) $this->order->id,
            false
        );
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
        $this->cart->updateQty(1, self::activeProductId());
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
            destinationTag: $this->destinationTag,
            expiry: time() + 300,
        ));
    }

    private static function activeProductId(): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` WHERE `active` = 1'
        );
    }
}
