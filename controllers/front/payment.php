<?php

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use LedgerDirect\Presentation\PaymentIntentPresenter;
use LedgerDirect\Service\PaymentSyncService;
use LedgerDirect\Service\ServiceFactory;
use LedgerDirect\Storage\OrderPaymentIntentRepository;

if (!defined('_PS_VERSION_')) { exit; }

/**
 * The payment instructions page: what to send, where, and with which
 * destination tag.
 *
 * The quote shown here is the stored one and stays fixed until its expiry
 * passes. Reloading the page never recomputes it — a customer who is mid-way
 * through a wallet transfer must not have the amount change underneath them.
 * Once expired, refreshing is an explicit action, and it updates the stored
 * record in place so the destination account and tag stay the same.
 */
class LedgerdirectPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    private ?Order $order = null;
    private bool $orderResolved = false;

    /** Set when the customer asked us to check and nothing had arrived yet. */
    private bool $checkedWithoutResult = false;

    /**
     * The manual "I have sent it, check now" path.
     *
     * Runs before initContent(), so a payment found here redirects instead of
     * rendering instructions the customer no longer needs. Deliberately tied
     * to a button rather than to every page load: a check hits the XRPL node,
     * and a plain reload must stay free.
     *
     * This is also the whole no-JavaScript story. Polling is a convenience for
     * browsers that run it; without this button, a customer with scripts
     * disabled would have no way to move the page forward at all.
     */
    public function postProcess()
    {
        if (!Tools::isSubmit('ld_check')) {
            return;
        }

        $order = $this->loadAuthorisedOrder();
        if ($order === null || !PaymentSyncService::isAwaitingPayment($order)) {
            return;
        }

        if (PaymentSyncService::create()->syncAndMatchOrder((int) $order->id)) {
            Tools::redirect($this->confirmationUrl($order));

            return;
        }

        $this->checkedWithoutResult = true;
    }

    /**
     * Asset registration belongs here, not in initContent(): by the time the
     * latter runs, PrestaShop has already collected the page's media.
     */
    public function setMedia()
    {
        $result = parent::setMedia();

        $this->registerJavascript(
            'ledgerdirect-payment',
            'modules/' . $this->module->name . '/views/js/payment.js',
            ['position' => 'bottom', 'priority' => 200]
        );

        return $result;
    }

    public function initContent()
    {
        parent::initContent();

        if (!($this->module instanceof Ledgerdirect)) {
            Tools::redirect('index.php?controller=history');

            return;
        }

        $order = $this->loadAuthorisedOrder();
        if ($order === null) {
            // Deliberately vague and pointed at the customer's own order list:
            // distinguishing "no such order" from "not your order" here would
            // let anyone probe for valid order ids.
            Tools::redirect('index.php?controller=history');

            return;
        }

        // A finished order has no payment page. Whatever happened — settled,
        // cancelled, refunded — the instructions are stale and showing them
        // would invite a second payment.
        if (!PaymentSyncService::isAwaitingPayment($order)) {
            Tools::redirect($this->confirmationUrl($order));

            return;
        }

        $repository = new OrderPaymentIntentRepository();

        try {
            $paymentIntent = $this->resolvePaymentIntent($order, $repository);
        } catch (Throwable $exception) {
            ServiceFactory::getInstance()->getLogger()->error('Could not present the payment page', [
                'id_order' => (int) $order->id,
                'exception' => $exception->getMessage(),
            ]);
            $paymentIntent = null;
        }

        $orderCurrency = new Currency((int) $order->id_currency);

        $this->context->smarty->assign([
            'ld_order_reference' => $order->reference,
            // Tools::displayPrice() is gone in PrestaShop 9; the locale is the
            // supported way to format a price now.
            'ld_order_total' => $this->context->getCurrentLocale()
                ->formatPrice((float) $order->total_paid, $orderCurrency->iso_code),
            'ld_history_url' => $this->context->link->getPageLink('history', true),
            'ld_self_url' => $this->selfUrl($order),
            'ld_checked_no_payment' => $this->checkedWithoutResult,
            'ld_poll_url' => $this->context->link->getModuleLink(
                $this->module->name,
                'poll',
                ['id_order' => (int) $order->id, 'key' => $order->secure_key],
                true
            ),
            'ld_intent' => $paymentIntent === null ? null : PaymentIntentPresenter::present($paymentIntent),
        ]);

        $this->setTemplate('module:ledgerdirect/views/templates/front/payment.tpl');
    }

    /**
     * Returns the quote to display.
     *
     * Only two things produce a new quote: an order that somehow has none
     * (the intent failed to store at checkout), and an explicit refresh of an
     * expired one. Both go through quoteForOrder($existing), which keeps the
     * destination account and tag — the tag is what ties an incoming ledger
     * transaction back to this order, so reallocating it would orphan a
     * payment already in flight.
     */
    private function resolvePaymentIntent(Order $order, OrderPaymentIntentRepository $repository): ?PaymentIntent
    {
        $paymentIntent = $repository->find((int) $order->id);

        $expired = $paymentIntent !== null && PaymentIntentPresenter::isExpired($paymentIntent);
        $refreshRequested = Tools::isSubmit('ld_refresh');

        if ($paymentIntent !== null && !($expired && $refreshRequested)) {
            return $paymentIntent;
        }

        $asset = $paymentIntent->baseAsset ?? self::assetFromOrder($order);
        if ($asset === null) {
            return $paymentIntent;
        }

        $currency = new Currency((int) $order->id_currency);
        $refreshed = ServiceFactory::getInstance()->getPaymentIntentService()->quoteForOrder(
            (float) $order->total_paid,
            $currency->iso_code,
            $asset,
            $paymentIntent
        );

        $repository->save((int) $order->id, $refreshed);

        return $refreshed;
    }

    /**
     * Loads the order only if the request proves it belongs to the requester.
     *
     * `secure_key` is what makes the page reachable for a guest checkout too,
     * so it is compared in constant time — it is the only secret guarding the
     * page.
     */
    private function loadAuthorisedOrder(): ?Order
    {
        // Memoised: postProcess() and initContent() both need it, and the
        // second load would come back from PrestaShop's query cache anyway —
        // stale, if postProcess() just settled the order.
        if ($this->orderResolved) {
            return $this->order;
        }

        $this->orderResolved = true;
        $this->order = $this->resolveAuthorisedOrder();

        return $this->order;
    }

    private function resolveAuthorisedOrder(): ?Order
    {
        $orderId = (int) Tools::getValue('id_order');
        if ($orderId <= 0) {
            return null;
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            return null;
        }

        $key = (string) Tools::getValue('key');
        if ($key !== '' && hash_equals((string) $order->secure_key, $key)) {
            return $order;
        }

        // Fallback for a logged-in customer arriving without the key, e.g. from
        // their order history.
        $customerId = (int) $this->context->customer->id;
        if ($customerId > 0 && $customerId === (int) $order->id_customer) {
            return $order;
        }

        return null;
    }

    /**
     * Recovers the asset from the order's payment label for the one case where
     * the intent is missing entirely: the order was created but storing the
     * intent failed. The label is written by this module in the validation
     * controller, so its shape is ours, not free text.
     */
    private static function assetFromOrder(Order $order): ?string
    {
        foreach (Ledgerdirect::SUPPORTED_ASSETS as $asset) {
            if (str_contains((string) $order->payment, '(' . $asset . ')')) {
                return $asset;
            }
        }

        return null;
    }

    private function selfUrl(Order $order): string
    {
        return $this->context->link->getModuleLink(
            $this->module->name,
            'payment',
            ['id_order' => (int) $order->id, 'key' => $order->secure_key],
            true
        );
    }

    private function confirmationUrl(Order $order): string
    {
        return $this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $order->id_cart,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $order->id,
            'key' => $order->secure_key,
        ]);
    }
}
