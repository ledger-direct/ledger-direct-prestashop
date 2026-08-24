<?php

use LedgerDirect\Service\PaymentSyncService;
use LedgerDirect\Storage\OrderPaymentIntentRepository;

if (!defined('_PS_VERSION_')) { exit; }

/**
 * Checks one order while its customer watches the payment page.
 *
 * Scoped to a single order and guarded by the same secure_key as the payment
 * page itself — this endpoint syncs the ledger on demand, so it must not be
 * something an anonymous caller can hammer with arbitrary order ids.
 */
class LedgerdirectPollModuleFrontController extends ModuleFrontController
{
    public $content_only = true;
    public $ssl = true;

    public function postProcess()
    {
        $order = $this->loadAuthorisedOrder();

        if ($order === null) {
            header('HTTP/1.1 403 Forbidden');
            $this->ajaxRender(json_encode(['error' => 'forbidden']));
            exit;
        }

        // Already settled (or cancelled) — tell the page to stop polling and
        // move on, without touching the ledger.
        if (!PaymentSyncService::isAwaitingPayment($order)) {
            $this->ajaxRender(json_encode(['status' => 'done', 'redirect' => $this->confirmationUrl($order)]));
            exit;
        }

        $settled = PaymentSyncService::create()->syncAndMatchOrder((int) $order->id);

        if ($settled) {
            $this->ajaxRender(json_encode(['status' => 'done', 'redirect' => $this->confirmationUrl($order)]));
            exit;
        }

        $paymentIntent = (new OrderPaymentIntentRepository())->find((int) $order->id);
        $expiry = $paymentIntent?->expiry;

        $this->ajaxRender(json_encode([
            'status' => 'waiting',
            'seconds_left' => $expiry === null ? null : max(0, $expiry - time()),
        ]));
        exit;
    }

    private function loadAuthorisedOrder(): ?Order
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

        $customerId = (int) $this->context->customer->id;

        return ($customerId > 0 && $customerId === (int) $order->id_customer) ? $order : null;
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
