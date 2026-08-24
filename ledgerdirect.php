<?php

use LedgerDirect\Admin\ConfigurationForm;
use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopConfigProvider;
use LedgerDirect\Service\ServiceFactory;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) { exit; }

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
        $this->version = '0.1.0';
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

            $amount = is_array($quote->amountRequested)
                ? number_format((float) $quote->amountRequested['value'], 2, '.', '')
                : number_format($quote->amountRequested, 5, '.', '');

            $this->smarty->assign([
                'ld_asset' => $asset,
                'ld_amount' => $amount,
                'ld_network' => $network,
                'ld_is_testnet' => $network === PrestaShopConfigProvider::NETWORK_TESTNET,
            ]);

            $option = new PaymentOption();
            $option->setModuleName($this->name)
                ->setCallToActionText($this->trans('Pay with %s', [$asset], 'Modules.Ledgerdirect.Shop'))
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
     * The order state install() created — the one an order sits in between
     * "customer committed" and "transaction seen on the ledger".
     */
    public static function getAwaitingPaymentStateId(): int
    {
        return Installer::getOrderStateId();
    }
}
