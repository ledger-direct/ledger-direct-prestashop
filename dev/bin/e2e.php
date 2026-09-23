<?php

/**
 * Helper for the end-to-end harness (ledger-direct/ledger-direct-e2e).
 *
 * Runs inside the PrestaShop container, as www-data:
 *   php modules/ledgerdirect/dev/bin/e2e.php <command> [--key=value …]
 *
 * Everything the harness cannot do over HTTP without a full checkout: placing
 * an order that waits for an XRPL payment, pointing the shop at a fresh
 * receiving account, and reading the order's state straight from storage.
 * Everything a customer does — the payment page, the poll, the check button,
 * the cron — the harness calls over HTTP like a customer would.
 *
 * Output is one JSON document on stdout. Dev tooling only: dev/ is
 * export-ignored and never part of the module archive.
 */
declare(strict_types=1);

use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopConfigProvider;
use LedgerDirect\Service\ServiceFactory;
use LedgerDirect\Storage\OrderPaymentIntentRepository;

$root = dirname(__DIR__, 4); // modules/ledgerdirect/dev/bin -> shop root
require $root . '/config/config.inc.php';
require_once $root . '/app/AppKernel.php';
require_once $root . '/app/FrontKernel.php';
$kernel = new FrontKernel('prod', false);
$kernel->boot();
Context::getContext()->container = $kernel->getContainer();

$args = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $args[$m[1]] = $m[2];
    }
}
$command = $argv[1] ?? 'help';

function emit(array $data): never
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function db(): Db
{
    return Db::getInstance();
}

function currentState(int $orderId): int
{
    return (int) db()->getValue('SELECT current_state FROM ' . _DB_PREFIX_ . 'orders WHERE id_order = ' . $orderId, false);
}

switch ($command) {
    case 'configure':
        // The shop's receiving account for this run, plus network and assets.
        $account = $args['account'] ?? fail('--account is required');
        Configuration::updateValue(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT, $account);
        Configuration::updateValue(PrestaShopConfigProvider::KEY_NETWORK, $args['network'] ?? 'testnet');
        $assets = explode(',', $args['assets'] ?? 'XRP');
        Configuration::updateValue(PrestaShopConfigProvider::KEY_ASSET_XRP, in_array('XRP', $assets, true) ? 1 : 0);
        Configuration::updateValue(PrestaShopConfigProvider::KEY_ASSET_RLUSD, in_array('RLUSD', $assets, true) ? 1 : 0);
        Configuration::updateValue(PrestaShopConfigProvider::KEY_ASSET_USDC, in_array('USDC', $assets, true) ? 1 : 0);
        Configuration::updateValue(PrestaShopConfigProvider::KEY_QUOTE_EXPIRY, (int) ($args['quote-expiry'] ?? 300));
        emit([
            'account' => $account,
            'network' => Configuration::get(PrestaShopConfigProvider::KEY_NETWORK),
            'assets' => $assets,
            'quote_expiry' => (int) Configuration::get(PrestaShopConfigProvider::KEY_QUOTE_EXPIRY),
            'cron_url' => Context::getContext()->link->getModuleLink('ledgerdirect', 'cron', ['token' => PrestaShopConfigProvider::getCronToken()], true),
        ]);

        // no break
    case 'create-order':
        // An order in "Awaiting XRPL payment" with a real quote from the core
        // (oracle price, destination tag from the counter) — the same path the
        // validation controller takes, minus the browser.
        $asset = $args['asset'] ?? 'XRP';
        $customerId = (int) ($args['customer'] ?? 2);
        $productId = (int) ($args['product'] ?? db()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE active = 1 ORDER BY price ASC'));
        $quantity = (int) ($args['quantity'] ?? 1);

        $context = Context::getContext();
        $customer = new Customer($customerId);
        if (!Validate::isLoadedObject($customer)) {
            fail("customer {$customerId} not found");
        }
        $addressId = (int) db()->getValue('SELECT id_address FROM ' . _DB_PREFIX_ . 'address WHERE id_customer = ' . $customerId . ' AND deleted = 0');
        $context->customer = $customer;
        $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

        $cart = new Cart();
        $cart->id_customer = $customerId;
        $cart->id_address_delivery = $addressId;
        $cart->id_address_invoice = $addressId;
        $cart->id_lang = (int) $context->language->id;
        $cart->id_currency = (int) $context->currency->id;
        $cart->id_carrier = (int) ($args['carrier'] ?? 2);
        $cart->secure_key = $customer->secure_key;
        $cart->add();
        $context->cart = $cart;
        $cart->updateQty($quantity, $productId);
        $cart = new Cart((int) $cart->id);
        $context->cart = $cart;

        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $services = ServiceFactory::getInstance();
        $intent = $services->getPaymentIntentService()->quoteForOrder($total, $context->currency->iso_code, $asset);

        $module = Module::getInstanceByName('ledgerdirect');
        $module->validateOrder((int) $cart->id, Installer::getOrderStateId(), $total, $module->displayName . ' (' . $asset . ')', null, [], (int) $context->currency->id, false, $customer->secure_key);
        $orderId = (int) $module->currentOrder;
        (new OrderPaymentIntentRepository())->save($orderId, $intent);

        $order = new Order($orderId);
        $link = $context->link;
        emit([
            'id_order' => $orderId,
            'reference' => $order->reference,
            'key' => $order->secure_key,
            'total' => $total,
            'currency' => $context->currency->iso_code,
            'page' => $link->getModuleLink('ledgerdirect', 'payment', ['id_order' => $orderId, 'key' => $order->secure_key], true),
            'poll' => $link->getModuleLink('ledgerdirect', 'poll', ['id_order' => $orderId, 'key' => $order->secure_key], true),
            'cron' => $link->getModuleLink('ledgerdirect', 'cron', ['token' => PrestaShopConfigProvider::getCronToken()], true),
            // What the intent says — the harness reads the *displayed* amount from the page and may compare.
            'intent' => ['destination_account' => $intent->destinationAccount, 'destination_tag' => $intent->destinationTag, 'amount_requested' => $intent->amountRequested, 'expiry' => $intent->expiry],
        ]);

        // no break
    case 'order-state':
        $orderId = (int) ($args['order'] ?? fail('--order is required'));
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            fail("order {$orderId} not found");
        }
        $stateId = currentState($orderId);
        $stateName = (string) db()->getValue('SELECT name FROM ' . _DB_PREFIX_ . 'order_state_lang WHERE id_order_state = ' . $stateId . ' AND id_lang = ' . (int) Configuration::get('PS_LANG_DEFAULT'), false);
        $payments = db()->executeS('SELECT transaction_id, amount FROM ' . _DB_PREFIX_ . 'order_payment WHERE order_reference = "' . pSQL($order->reference) . '"', true, false) ?: [];
        $intent = (new OrderPaymentIntentRepository())->find($orderId);
        $history = db()->executeS('SELECT id_order_state, date_add FROM ' . _DB_PREFIX_ . 'order_history WHERE id_order = ' . $orderId . ' ORDER BY id_order_history ASC', true, false) ?: [];
        emit([
            'id_order' => $orderId,
            'state' => ['id' => $stateId, 'name' => $stateName,
                'is_awaiting' => $stateId === Installer::getOrderStateId(),
                'is_incomplete' => $stateId === Installer::getIncompleteOrderStateId(),
                'is_paid' => $stateId === (int) Configuration::get('PS_OS_PAYMENT')],
            'total_paid' => (float) $order->total_paid,
            'total_paid_real' => (float) db()->getValue('SELECT total_paid_real FROM ' . _DB_PREFIX_ . 'orders WHERE id_order = ' . $orderId, false),
            'payments' => $payments,
            'history' => $history,
            'intent' => $intent === null ? null : ['hash' => $intent->hash, 'amount_paid' => $intent->amountPaid, 'amount_requested' => $intent->amountRequested,
                'status' => PaymentStatus::fromIntent($intent, ServiceFactory::getInstance()->getSettlementPolicy())->state()],
        ]);

        // no break
    case 'sync-marker':
        // The throttle's mark for the configured account: its stored value
        // changes only when a sync ran. Two polls inside the window leave it
        // untouched — that is how PS-08 is proven without grepping logs.
        $config = ServiceFactory::getInstance()->getConfigProvider();
        $key = 'ledger-direct.sync.v1.' . $config->getNetwork(PrestaShopConfigProvider::CHAIN_XRPL) . '.' . $config->getDestinationAccount(PrestaShopConfigProvider::CHAIN_XRPL);
        $row = db()->getRow('SELECT value, expires_at FROM ' . _DB_PREFIX_ . Installer::TABLE_RATE_CACHE . ' WHERE cache_key = "' . pSQL($key) . '"', false);
        emit(['key' => $key, 'value' => $row['value'] ?? null, 'expires_at' => isset($row['expires_at']) ? (int) $row['expires_at'] : null]);

        // no break
    case 'close-order':
        // PS-11: the merchant cancels.
        $orderId = (int) ($args['order'] ?? fail('--order is required'));
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            fail("order {$orderId} not found");
        }
        try {
            $order->setCurrentState((int) Configuration::get('PS_OS_CANCELED'));
        } catch (Throwable $e) {
            // The state is set before the mail goes out; a missing mailer is not a failure here.
        }
        emit(['id_order' => $orderId, 'state' => currentState($orderId)]);

    default:
        emit(['commands' => ['configure', 'create-order', 'order-state', 'sync-marker', 'close-order']]);
}
