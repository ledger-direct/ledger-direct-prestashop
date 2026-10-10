<?php

use LedgerDirect\Admin\ConfigurationForm;
use LedgerDirect\Admin\OrderPanel;
use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopConfigProvider;
use LedgerDirect\Presentation\PaymentIntentPresenter;
use LedgerDirect\Service\ServiceFactory;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

// Bundled dependencies: the core, its PSR interfaces, and Guzzle as the
// concrete PSR-18 client. Release artefacts ship self-contained (see the core's
// CLAUDE.md, "Packaging"), so vendor/ is expected to be present here.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class Ledgerdirect extends PaymentModule
{
    /** Offered in this order in the checkout. */
    public const SUPPORTED_ASSETS = ['XRP', 'RLUSD', 'USDC'];

    public function __construct()
    {
        $this->name = 'ledgerdirect';
        $this->tab = 'payments_gateways';
        $this->version = '0.5.1';
        $this->author = 'Hardcastle';
        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('LedgerDirect', [], 'Modules.Ledgerdirect.Admin');
        $this->description = $this->trans(
            'Accept XRP, RLUSD and USDC directly on the XRP Ledger.',
            [],
            'Modules.Ledgerdirect.Admin'
        );
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayAdminOrderSide')
            && $this->registerHook('overrideLayoutTemplate')
            && Installer::install($this->name);
    }

    public function uninstall(): bool
    {
        return Installer::uninstall()
            && parent::uninstall();
    }

    /**
     * One PaymentOption per enabled asset, each priced through the core.
     *
     * Uses PriceService and *not* PaymentIntentService: the latter also
     * allocates a destination tag, and this hook runs on every render of the
     * checkout payment step. Quoting here and intent-building in the
     * validation controller keeps tag allocation tied to an actual order
     * instead of to page views.
     *
     * @param array<string, mixed> $params
     *
     * @return PaymentOption[]
     */
    public function hookPaymentOptions(array $params): array
    {
        if (!$this->active) {
            return [];
        }

        $cart = $params['cart'] ?? null;
        if (!Validate::isLoadedObject($cart)) {
            return [];
        }

        $services = ServiceFactory::getInstance();
        $configProvider = $services->getConfigProvider();

        // No receiving address configured means there is nowhere for the money
        // to go — offering the method would produce an unpayable order.
        if ($configProvider->getDestinationAccount(PrestaShopConfigProvider::CHAIN_XRPL) === '') {
            return [];
        }

        $network = $configProvider->getNetwork(PrestaShopConfigProvider::CHAIN_XRPL);
        $currency = new Currency((int) $cart->id_currency);
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);

        $options = [];
        foreach (self::SUPPORTED_ASSETS as $asset) {
            if (!$configProvider->isAssetEnabled(PrestaShopConfigProvider::CHAIN_XRPL, $asset)) {
                continue;
            }

            try {
                $quote = $services->getPriceService()
                    ->getCryptoPriceForOrder($total, $currency->iso_code, $asset, $network);
            } catch (Throwable $exception) {
                // An asset that cannot be priced right now is simply not
                // offered. Letting the customer pick it would mean failing
                // after they committed, which is the worse moment.
                $services->getLogger()->error('Hiding payment option, no price available', [
                    'asset' => $asset,
                    'currency' => $currency->iso_code,
                    'exception' => $exception->getMessage(),
                ]);
                continue;
            }

            // The amount as the core states it — a plain decimal, not rounded a
            // second time here; the payment page shows the same number.
            $this->smarty->assign([
                'ld_asset' => $asset,
                'ld_amount' => PaymentIntentPresenter::plainAmount($quote->amountRequested),
                'ld_network' => $network,
                'ld_is_testnet' => $network === PrestaShopConfigProvider::NETWORK_TESTNET,
            ]);

            $option = new PaymentOption();
            $option->setModuleName($this->name)
                ->setCallToActionText($this->trans('Pay with %s', [$asset], 'Modules.Ledgerdirect.Shop'))
                ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/' . strtolower($asset) . '_payment.svg'))
                ->setAction($this->context->link->getModuleLink(
                    $this->name,
                    'validation',
                    ['ld_asset' => $asset],
                    true
                ))
                ->setAdditionalInformation(
                    $this->fetch('module:ledgerdirect/views/templates/front/payment_option.tpl')
                );

            $options[] = $option;
        }

        return $options;
    }

    /**
     * The settings screen in the Back Office (Modules → LedgerDirect →
     * Configure). Its presence is also what makes PrestaShop show the
     * Configure button at all.
     */
    public function getContent(): string
    {
        return (new ConfigurationForm($this))->handle();
    }

    /**
     * The LedgerDirect panel on the Back Office order page: payment state,
     * what was asked for, what arrived, and every transaction on the order's
     * destination tag with a link to the explorer. Empty for orders that were
     * not paid through this module.
     *
     * @param array<string, mixed> $params
     */
    public function hookDisplayAdminOrderSide(array $params): string
    {
        return (new OrderPanel($this))->render((int) ($params['id_order'] ?? 0));
    }

    /**
     * The payment page stands on its own, without the theme's header, footer
     * and columns — it is the page a customer looks at while a wallet is
     * open next to it, and nothing on it should lead away. PrestaShop asks
     * every module for a layout; this one answers only for its own page and
     * hands back the theme's content-only layout, which keeps the theme's
     * <head> and stylesheets. Any other page keeps whatever layout it has.
     *
     * The page is recognised by its controller, not by the `entity` name:
     * for every front controller of a payment module PrestaShop names the
     * page `module-payment-submit`, so the name cannot tell the payment page
     * from the poll or the cron endpoint.
     *
     * @param array<string, mixed> $params
     */
    public function hookOverrideLayoutTemplate(array $params): ?string
    {
        if (!($params['controller'] ?? null) instanceof LedgerdirectPaymentModuleFrontController) {
            return null;
        }

        $layout = 'layout-content-only';
        if (!is_file(_PS_THEME_DIR_ . 'templates/layouts/' . $layout . '.tpl')) {
            // A theme without that layout keeps its default one; the page
            // then renders inside the theme's frame, which is still a page.
            return null;
        }

        return $this->context->shop->theme->getLayoutPath($layout);
    }

    /**
     * The order state install() created — the one an order sits in between
     * "customer committed" and "transaction seen on the ledger".
     */
    public static function getAwaitingPaymentStateId(): int
    {
        return Installer::getOrderStateId();
    }
}
