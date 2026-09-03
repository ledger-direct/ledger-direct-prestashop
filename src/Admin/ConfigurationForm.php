<?php

declare(strict_types=1);

namespace LedgerDirect\Admin;

use Configuration;
use Hardcastle\LedgerDirect\Core\Xrpl\StablecoinRegistry;
use LedgerDirect\Port\PrestaShopConfigProvider;
use LedgerDirect\Validation\XrplAddress;
use Module;
use PrestaShopBundle\Translation\TranslatorInterface;

/**
 * The module's settings screen.
 *
 * What is *not* here is as deliberate as what is: issuer addresses are shown
 * but never editable. A wrong issuer sends customer funds to a dead trustline,
 * so those values live in the core's StablecoinRegistry and this form only
 * reads them (INVARIANTS.md, "Security").
 */
final class ConfigurationForm
{
    private const SUBMIT_ACTION = 'submitLedgerdirectConfiguration';

    /**
     * Translation domain. PrestaShop strips the dots to find the catalogue,
     * so this maps to translations/<locale>/ModulesLedgerdirectAdmin.<locale>.xlf.
     */
    private const DOMAIN = 'Modules.Ledgerdirect.Admin';

    private const QUOTE_EXPIRY_MIN = 60;
    private const QUOTE_EXPIRY_MAX = 3600;

    public function __construct(private readonly \Module $module)
    {
    }

    /**
     * Module::trans() is protected, so a collaborator cannot call it — the
     * translator itself is the public seam. Same catalogues, same domains.
     */
    private function translator(): TranslatorInterface
    {
        return $this->module->getTranslator();
    }

    public function handle(): string
    {
        $output = '';

        if (\Tools::isSubmit(self::SUBMIT_ACTION)) {
            $errors = $this->save();

            $output .= $errors === []
                ? $this->module->displayConfirmation($this->translator()->trans('Settings saved.', [], self::DOMAIN))
                : $this->module->displayError(implode('<br>', $errors));
        }

        return $output . $this->renderCronPanel() . $this->renderForm();
    }

    /**
     * @return string[] validation errors; empty means everything was written
     */
    private function save(): array
    {
        $errors = [];

        $destinationAccount = trim((string) \Tools::getValue(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT));
        $network = (string) \Tools::getValue(PrestaShopConfigProvider::KEY_NETWORK);
        $quoteExpiry = (int) \Tools::getValue(PrestaShopConfigProvider::KEY_QUOTE_EXPIRY);

        $enabledAssets = [];
        foreach (self::assetKeys() as $asset => $key) {
            if ((bool) \Tools::getValue($key)) {
                $enabledAssets[] = $asset;
            }
        }

        if ($destinationAccount !== '' && !XrplAddress::isValid($destinationAccount)) {
            $errors[] = $this->translator()->trans('The receiving address is not a valid XRPL address. It starts with "r" and contains no 0, O, I or l.', [], self::DOMAIN);
        }

        if ($destinationAccount === '' && $enabledAssets !== []) {
            $errors[] = $this->translator()->trans('Enter a receiving address, or disable every payment method. Without an address there is nowhere for the money to go.', [], self::DOMAIN);
        }

        if (!in_array($network, [PrestaShopConfigProvider::NETWORK_MAINNET, PrestaShopConfigProvider::NETWORK_TESTNET], true)) {
            $errors[] = $this->translator()->trans('Choose either mainnet or testnet.', [], self::DOMAIN);
        }

        if ($quoteExpiry < self::QUOTE_EXPIRY_MIN || $quoteExpiry > self::QUOTE_EXPIRY_MAX) {
            $errors[] = $this->translator()->trans(
                'The quote validity must be between %d and %d seconds.',
                [self::QUOTE_EXPIRY_MIN, self::QUOTE_EXPIRY_MAX],
                self::DOMAIN
            );
        }

        if ($errors !== []) {
            return $errors;
        }

        \Configuration::updateValue(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT, $destinationAccount);
        \Configuration::updateValue(PrestaShopConfigProvider::KEY_NETWORK, $network);
        \Configuration::updateValue(PrestaShopConfigProvider::KEY_QUOTE_EXPIRY, $quoteExpiry);

        foreach (self::assetKeys() as $key) {
            \Configuration::updateValue($key, (bool) \Tools::getValue($key));
        }

        return [];
    }

    private function renderForm(): string
    {
        $language = (int) \Context::getContext()->language->id;

        $helper = new \HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->module->name;
        $helper->token = \Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = 'index.php?controller=AdminModules&configure=' . $this->module->name;
        $helper->submit_action = self::SUBMIT_ACTION;
        $helper->default_form_language = $language;
        $helper->allow_employee_form_lang = $language;
        $helper->title = $this->module->displayName;
        $helper->show_toolbar = false;

        $helper->fields_value = [
            PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT => \Configuration::get(PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT),
            PrestaShopConfigProvider::KEY_NETWORK => \Configuration::get(PrestaShopConfigProvider::KEY_NETWORK),
            PrestaShopConfigProvider::KEY_QUOTE_EXPIRY => \Configuration::get(PrestaShopConfigProvider::KEY_QUOTE_EXPIRY),
        ];

        foreach (self::assetKeys() as $key) {
            $helper->fields_value[$key] = \Configuration::get($key);
        }

        return $helper->generateForm([$this->formDefinition()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formDefinition(): array
    {
        $inputs = [
            [
                'type' => 'text',
                'label' => $this->translator()->trans('Receiving address', [], self::DOMAIN),
                'name' => PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT,
                'required' => false,
                'desc' => $this->translator()->trans('Your XRPL account. Customers pay to this address, each order identified by its own destination tag.', [], self::DOMAIN),
            ],
            [
                'type' => 'select',
                'label' => $this->translator()->trans('Network', [], self::DOMAIN),
                'name' => PrestaShopConfigProvider::KEY_NETWORK,
                'options' => [
                    'query' => [
                        ['id' => PrestaShopConfigProvider::NETWORK_TESTNET, 'name' => $this->translator()->trans('Testnet (no real funds)', [], self::DOMAIN)],
                        ['id' => PrestaShopConfigProvider::NETWORK_MAINNET, 'name' => $this->translator()->trans('Mainnet (live)', [], self::DOMAIN)],
                    ],
                    'id' => 'id',
                    'name' => 'name',
                ],
                'desc' => $this->translator()->trans('The receiving address must belong to the selected network. A mainnet address holds no funds on testnet and the other way round.', [], self::DOMAIN),
            ],
        ];

        foreach (self::assetKeys() as $asset => $key) {
            $inputs[] = [
                'type' => 'switch',
                'label' => $this->translator()->trans('Accept %s', [$asset], self::DOMAIN),
                'name' => $key,
                'is_bool' => true,
                'desc' => $this->assetDescription($asset),
                'values' => [
                    ['id' => $key . '_on', 'value' => 1, 'label' => $this->translator()->trans('Yes', [], self::DOMAIN)],
                    ['id' => $key . '_off', 'value' => 0, 'label' => $this->translator()->trans('No', [], self::DOMAIN)],
                ],
            ];
        }

        $inputs[] = [
            'type' => 'text',
            'label' => $this->translator()->trans('Quote validity (seconds)', [], self::DOMAIN),
            'name' => PrestaShopConfigProvider::KEY_QUOTE_EXPIRY,
            'required' => true,
            'desc' => $this->translator()->trans('How long the exchange rate shown to a customer stays fixed. Once it lapses the customer is asked to refresh the amount; the destination tag stays the same.', [], self::DOMAIN),
        ];

        return [
            'form' => [
                'legend' => [
                    'title' => $this->translator()->trans('XRP Ledger settings', [], self::DOMAIN),
                    'icon' => 'icon-cogs',
                ],
                'input' => $inputs,
                'submit' => [
                    'title' => $this->translator()->trans('Save', [], self::DOMAIN),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ];
    }

    /**
     * For a stablecoin, spells out the issuer the shop will accept and the
     * trustline the merchant needs. Both are read from the core registry
     * rather than written down here.
     */
    private function assetDescription(string $asset): string
    {
        if ($asset === 'XRP') {
            return $this->translator()->trans('The native asset of the XRP Ledger. Needs no trustline.', [], self::DOMAIN);
        }

        $issuers = [];
        foreach ([PrestaShopConfigProvider::NETWORK_MAINNET, PrestaShopConfigProvider::NETWORK_TESTNET] as $network) {
            $issuers[] = $network . ': ' . self::issuerFor($asset, $network);
        }

        return $this->translator()->trans(
            'Your account needs a %s trustline to the issuer below before it can receive payments — without one they will fail on the ledger. The issuer is fixed and cannot be changed here. %s',
            [$asset, implode(' | ', $issuers)],
            self::DOMAIN
        );
    }

    /**
     * The registry exposes issuers only as part of a built amount, so a
     * zero-value amount is the read path. Reaching for it beats copying the
     * addresses into the adapter, which INVARIANTS.md rules out outright.
     */
    private static function issuerFor(string $asset, string $network): string
    {
        $registry = new StablecoinRegistry();

        $amount = $asset === 'RLUSD'
            ? $registry->getRLUSDAmount($network, '0')
            : $registry->getUSDCAmount($network, '0');

        return (string) ($amount['issuer'] ?? '');
    }

    /**
     * The cron URL, with its token, spelled out so a merchant can paste it
     * straight into their hosting panel. Without this the token is only
     * reachable through the database.
     */
    private function renderCronPanel(): string
    {
        $url = \Context::getContext()->link->getModuleLink(
            $this->module->name,
            'cron',
            ['token' => PrestaShopConfigProvider::getCronToken()],
            true
        );

        return '<div class="panel">'
            . '<div class="panel-heading">' . htmlspecialchars($this->translator()->trans('Payment confirmation', [], self::DOMAIN), ENT_QUOTES, 'UTF-8') . '</div>'
            . '<p>' . htmlspecialchars($this->translator()->trans('Call this URL from a cron job every few minutes. It confirms payments for customers who closed the payment page. Keep the token secret.', [], self::DOMAIN), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><code>' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</code></p>'
            . '</div>';
    }

    /**
     * @return array<string, string> asset => configuration key
     */
    private static function assetKeys(): array
    {
        return [
            'XRP' => PrestaShopConfigProvider::KEY_ASSET_XRP,
            'RLUSD' => PrestaShopConfigProvider::KEY_ASSET_RLUSD,
            'USDC' => PrestaShopConfigProvider::KEY_ASSET_USDC,
        ];
    }
}
