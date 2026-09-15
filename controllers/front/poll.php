<?php

use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use LedgerDirect\Service\PaymentSyncService;
use LedgerDirect\Service\ServiceFactory;
use LedgerDirect\Storage\OrderPaymentIntentRepository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The payment-status endpoint: "is this order paid?" while its customer
 * watches the payment page.
 *
 * The answer is the core's PaymentStatus payload (INVARIANTS.md, "Payment
 * status") — the same shape every LedgerDirect plugin returns — plus the one
 * field only this platform can build: the `redirect` URL. It is present as
 * soon as the order no longer waits for payment, whatever ended the wait:
 * settled on-chain, or cancelled/paid by hand in the Back Office, for which
 * the contract has no state. The payment page redirects those orders too.
 *
 * Scoped to a single order and guarded by the same secure_key as the payment
 * page itself — this endpoint syncs the ledger on demand, so it must not be
 * something an anonymous caller can hammer with arbitrary order ids. The sync
 * behind it is throttled (SyncThrottle); within the window the answer comes
 * from the stored intent, in the same shape.
 */
class LedgerdirectPollModuleFrontController extends ModuleFrontController
{
    public $content_only = true;
    public $ssl = true;

    public function postProcess()
    {
        $order = $this->loadAuthorisedOrder();

        if ($order === null) {
            $this->respond(403, ['error' => 'forbidden']);
        }

        $orderId = (int) $order->id;
        $repository = new OrderPaymentIntentRepository();

        try {
            $paymentIntent = $repository->find($orderId);

            if ($paymentIntent !== null && PaymentSyncService::isAwaitingPayment($order)) {
                PaymentSyncService::create()->syncAndMatchOrder($orderId);

                // Re-read: the match may have stored a fulfillment, or settled
                // the order. The repository reads past the query cache.
                $paymentIntent = $repository->find($orderId);
            }
        } catch (Throwable $exception) {
            ServiceFactory::getInstance()->getLogger()->error('Poll could not read the payment intent', [
                'id_order' => $orderId,
                'exception' => $exception->getMessage(),
            ]);
            $this->respond(500, ['error' => 'payment_intent_unreadable']);
        }

        if ($paymentIntent === null) {
            // The page renders an error and no poll URL for such an order, so
            // this only answers a hand-made request.
            $this->respond(404, ['error' => 'no_payment_intent']);
        }

        $payload = PaymentStatus::fromIntent(
            $paymentIntent,
            ServiceFactory::getInstance()->getSettlementPolicy()
        )->toArray();

        // Read from storage, not from the Order object loaded above: if the
        // sync settled the order in this very request, the object is stale.
        if (!PaymentSyncService::isAwaitingPaymentById($orderId)) {
            $payload['redirect'] = $this->confirmationUrl($order);
        }

        $this->respond(200, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(int $status, array $payload): never
    {
        http_response_code($status);
        header('Content-Type: application/json');

        $this->ajaxRender(json_encode($payload));
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
