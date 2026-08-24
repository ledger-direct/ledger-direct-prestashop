<?php

use LedgerDirect\Port\PrestaShopConfigProvider;
use LedgerDirect\Service\ServiceFactory;
use LedgerDirect\Storage\OrderPaymentIntentRepository;

if (!defined('_PS_VERSION_')) { exit; }

/**
 * Turns a cart into an order sitting in "Awaiting XRPL payment", with a
 * PaymentIntent attached, then sends the customer to the payment page.
 */
class LedgerdirectValidationModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        if (!($this->module instanceof Ledgerdirect) || !$this->module->active) {
            $this->redirectToCheckout();

            return;
        }

        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0) {
            $this->redirectToCheckout();

            return;
        }

        // The customer may have changed their address between picking the
        // method and submitting, which can make the module unavailable.
        $authorized = false;
        foreach (Module::getPaymentModules() as $paymentModule) {
            if ($paymentModule['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            $this->redirectToCheckout();

            return;
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            $this->redirectToCheckout();

            return;
        }

        $services = ServiceFactory::getInstance();

        // Never trust the asset from the request: it decides which currency
        // the customer is asked to send. Only an asset the merchant has
        // actually enabled is allowed through.
        $asset = (string) Tools::getValue('ld_asset');
        if (
            !in_array($asset, Ledgerdirect::SUPPORTED_ASSETS, true)
            || !$services->getConfigProvider()->isAssetEnabled(PrestaShopConfigProvider::CHAIN_XRPL, $asset)
        ) {
            $this->redirectToCheckout();

            return;
        }

        $currency = $this->context->currency;
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        // Quote before creating the order: if no oracle can price the asset,
        // the customer goes back to the checkout instead of ending up with an
        // order nobody can tell them how to pay.
        try {
            $paymentIntent = $services->getPaymentIntentService()
                ->quoteForOrder($total, $currency->iso_code, $asset);
        } catch (Throwable $exception) {
            $services->getLogger()->error('Could not build a payment intent, aborting checkout', [
                'asset' => $asset,
                'id_cart' => (int) $cart->id,
                'exception' => $exception->getMessage(),
            ]);
            $this->redirectToCheckout();

            return;
        }

        $this->module->validateOrder(
            (int) $cart->id,
            Ledgerdirect::getAwaitingPaymentStateId(),
            $total,
            $this->module->displayName . ' (' . $asset . ')',
            null,
            [],
            (int) $currency->id,
            false,
            $customer->secure_key
        );

        $orderId = (int) $this->module->currentOrder;

        try {
            (new OrderPaymentIntentRepository())->save($orderId, $paymentIntent);
        } catch (Throwable $exception) {
            // The order exists at this point, so this cannot abort the
            // checkout. The payment page re-quotes when it finds no intent.
            $services->getLogger()->error('Order created but payment intent could not be stored', [
                'id_order' => $orderId,
                'exception' => $exception->getMessage(),
            ]);
        }

        Tools::redirect($this->context->link->getModuleLink(
            $this->module->name,
            'payment',
            ['id_order' => $orderId, 'key' => $customer->secure_key],
            true
        ));
    }

    private function redirectToCheckout(): void
    {
        Tools::redirect('index.php?controller=order&step=1');
    }
}
