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

/*
 * The try-it shop's setup on top of Flashlight's pre-installed PrestaShop. Every part is safe to run
 * again.
 *
 *   install.php shop      first start: German as the second language, outgoing mail off, free
 *     shipping to every country, the other payment methods and the demo shop's home page blocks off, the demo
 *     catalogue of PrestaShop hidden (its customer John DOE stays: the end-to-end harness orders as him)
 *
 *   install.php config    every start: the shop's address, the demo login and the receiving account
 *     - LD_TESTNET_ACCOUNT if set (yours: it needs the RLUSD and USDC trust lines itself)
 *     - otherwise the shop's own testnet wallet: created from the faucet on the first start, with
 *       trust lines to the RLUSD and USDC issuers of the core's StablecoinRegistry, and created anew
 *       when the testnet was reset (see wallet.php)
 *     - XRP always; RLUSD and USDC when the account has the trust lines
 *
 *   install.php catalog   when the image brings a new catalogue: the category "LedgerDirect demo" with
 *     the demo article for 1 EUR (the smoke test orders it), the category "Miniatures" with the
 *     articles of catalog/catalog.json, and the home page that explains the demo and shows the
 *     miniatures. Categories are found again by URL key, articles by reference: an existing one gets
 *     its texts and price updated, never a second copy of its pictures.
 */
declare(strict_types=1);

use Hardcastle\LedgerDirect\Core\Xrpl\StablecoinRegistry;
use LedgerDirect\Port\PrestaShopConfigProvider;

const STATE = '/var/lib/ledger-direct';
const ACCOUNT_FILE = STATE . '/account';
const CRON_FILE = STATE . '/cron-path';
const WALLET_FILE = STATE . '/wallet.json';
const WALLET = '/usr/local/share/ledger-direct/wallet.php';
const CATALOG = '/usr/local/share/ledger-direct/catalog';
const ADDRESS = '/^r[1-9A-HJ-NP-Za-km-z]{24,34}$/';
const DEMO_REFERENCE = 'ledger-direct-demo';
const DEMO_CATEGORY = 'ledger-direct-demo';
/** catalog.json's languages, by PrestaShop's ISO code. */
const LANGUAGES = ['en' => 'en-GB', 'de' => 'de-DE'];

require '/var/www/html/config/config.inc.php';
require_once '/var/www/html/app/AppKernel.php';
require_once '/var/www/html/app/AdminKernel.php';
require_once '/var/www/html/modules/ledgerdirect/vendor/autoload.php';

global $kernel;
$kernel = new AdminKernel('prod', false);
$kernel->boot();
$context = Context::getContext();
$context->container = $kernel->getContainer();
$context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
Shop::setContext(Shop::CONTEXT_SHOP, (int) $context->shop->id);
$context->employee = new Employee(1);
$context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

function out(string $message): void
{
    fwrite(STDOUT, '[ledger-direct-try] ' . $message . "\n");
}

function table(string $name): string
{
    return _DB_PREFIX_ . $name;
}

/** Language id => catalog.json language key, for the languages the shop has. */
function languages(): array
{
    $ids = [];
    foreach (LANGUAGES as $iso => $key) {
        $id = (int) Language::getIdByIso($iso);
        if ($id > 0) {
            $ids[$id] = $key;
        }
    }

    return $ids;
}

/** One value per language, from a [language key => value] map (English where a language lacks one). */
function perLanguage(array $byKey): array
{
    $values = [];
    foreach (languages() as $id => $key) {
        $values[$id] = $byKey[$key] ?? $byKey['en-GB'];
    }

    return $values;
}

/** Runs the wallet tool; returns its exit code, passes its own log lines through. */
function wallet(string ...$args): int
{
    $command = 'php ' . escapeshellarg(WALLET) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    passthru($command, $code);

    return $code;
}

/**
 * The shop's own testnet account with its trust lines; [address, whether RLUSD and USDC can be paid].
 * The trust-line limits come from the core's StablecoinRegistry, the one source of issuer addresses.
 */
function ownWallet(): array
{
    if (is_file(WALLET_FILE) && wallet('exists', WALLET_FILE) === 3) {
        out('the testnet was reset since the last start; creating a new receiving account');
        unlink(WALLET_FILE);
    }
    if (!is_file(WALLET_FILE)) {
        ob_start();
        $code = wallet('create', WALLET_FILE);
        ob_end_clean();
        if ($code !== 0) {
            throw new RuntimeException('could not create a testnet account; set LD_TESTNET_ACCOUNT to an address of your own');
        }
    }
    $address = json_decode((string) file_get_contents(WALLET_FILE), true)['address'];

    $registry = new StablecoinRegistry();
    $lines = [$registry->getRLUSDAmount('testnet', '1000000'), $registry->getUSDCAmount('testnet', '1000000')];
    $trusted = wallet('trust', WALLET_FILE, json_encode($lines)) === 0;
    if (!$trusted) {
        out('the trust lines could not be set; RLUSD and USDC stay off for now, the next start tries again');
    }

    return [$address, $trusted];
}

function shop(): void
{
    $errors = [];
    if (!Language::getIdByIso('de')) {
        // The pack is in the image already (translations/sf-de-DE.zip): nothing is downloaded.
        Language::installLanguagePack('de', ['allow_accented_chars_url' => false], $errors);
        if ($errors) {
            throw new RuntimeException('German could not be installed: ' . implode('; ', $errors));
        }
    }

    // No mail server in this stack.
    Configuration::updateValue('PS_MAIL_METHOD', 3);
    Configuration::updateValue('PS_SHOP_NAME', 'LedgerDirect demo shop');
    Configuration::updateValue('PS_SHOP_EMAIL', (string) getenv('LD_ADMIN_EMAIL'));
    $shop = Context::getContext()->shop;
    $shop->name = 'LedgerDirect demo shop';
    $shop->update();

    // Any address will do: every country, "My carrier" (the one the harness uses) delivers everywhere
    // for free, the pickup carrier goes, and LedgerDirect is offered in every country (a module
    // takes the countries active when it was installed, here France and the US).
    $db = Db::getInstance();
    $db->update('country', ['active' => 1]);
    $db->update('carrier', ['is_free' => 1, 'shipping_handling' => 0], 'id_carrier = 2');
    $db->update('carrier', ['active' => 0], 'id_carrier <> 2');
    $db->execute('INSERT IGNORE INTO ' . table('carrier_zone') . ' (id_carrier, id_zone) SELECT 2, id_zone FROM ' . table('zone'));
    $db->execute('INSERT IGNORE INTO ' . table('module_country') . ' (id_module, id_shop, id_country)'
        . ' SELECT ' . (int) Module::getModuleIdByName('ledgerdirect') . ', ' . (int) Context::getContext()->shop->id . ', id_country FROM ' . table('country'));

    // Only LedgerDirect at the checkout; no PrestaShop demo slider, banner or product blocks on the
    // home page, no marketing integrations calling out from the storefront.
    foreach ([
        'ps_checkout', 'ps_wirepayment', 'ps_checkpayment', 'ps_cashondelivery',
        'ps_imageslider', 'ps_banner', 'ps_newproducts', 'ps_bestsellers', 'ps_specials',
        'psxmarketingwithgoogle', 'ps_facebook',
        // Its pictures load from http://modules/… in this Flashlight release (a broken URL).
        'blockreassurance',
    ] as $name) {
        $module = Module::getInstanceByName($name);
        if ($module && Module::isEnabled($name)) {
            $module->disable(true);
        }
    }

    // PrestaShop's demo catalogue stays in the database (its customer and addresses are used by the
    // harness), hidden from the storefront.
    Db::getInstance()->update('product', ['active' => 0]);
    Db::getInstance()->update('product_shop', ['active' => 0]);
    Db::getInstance()->update('category', ['active' => 0], 'id_category > 2');

    out('PrestaShop set up: English and German, free shipping, LedgerDirect as the only payment method');
}

function configure(): void
{
    $domain = (string) getenv('LD_DOMAIN');
    Db::getInstance()->update('shop_url', ['domain' => pSQL($domain), 'domain_ssl' => pSQL($domain)]);
    Configuration::updateGlobalValue('PS_SHOP_DOMAIN', $domain);
    Configuration::updateGlobalValue('PS_SHOP_DOMAIN_SSL', $domain);

    Db::getInstance()->update('employee', [
        'email' => pSQL((string) getenv('LD_ADMIN_EMAIL')),
        'passwd' => pSQL(password_hash((string) getenv('LD_ADMIN_PASSWORD'), PASSWORD_BCRYPT)),
        'firstname' => 'Demo',
        'lastname' => 'Admin',
    ], 'id_employee = 1');

    $own = trim((string) getenv('LD_TESTNET_ACCOUNT'));
    if ($own !== '' && !preg_match(ADDRESS, $own)) {
        throw new RuntimeException("LD_TESTNET_ACCOUNT is not an XRPL address: {$own}");
    }
    if ($own !== '') {
        [$account, $stablecoins] = [$own, getenv('LD_STABLECOINS') !== '0'];
    } else {
        [$account, $stablecoins] = ownWallet();
    }
    file_put_contents(ACCOUNT_FILE, $account);

    foreach ([
        PrestaShopConfigProvider::KEY_NETWORK => PrestaShopConfigProvider::NETWORK_TESTNET,
        PrestaShopConfigProvider::KEY_DESTINATION_ACCOUNT => $account,
        PrestaShopConfigProvider::KEY_QUOTE_EXPIRY => 300,
        PrestaShopConfigProvider::KEY_ASSET_XRP => 1,
        PrestaShopConfigProvider::KEY_ASSET_RLUSD => $stablecoins ? 1 : 0,
        PrestaShopConfigProvider::KEY_ASSET_USDC => $stablecoins ? 1 : 0,
    ] as $key => $value) {
        Configuration::updateValue($key, $value);
    }

    $cron = Context::getContext()->link->getModuleLink('ledgerdirect', 'cron', ['token' => PrestaShopConfigProvider::getCronToken()], false);
    file_put_contents(CRON_FILE, parse_url($cron, PHP_URL_PATH) . '?' . parse_url($cron, PHP_URL_QUERY));

    out("receiving account {$account} (" . ($own !== '' ? 'yours' : "the shop's own testnet wallet") . '); XRP'
        . ($stablecoins ? ', RLUSD and USDC' : ' only'));
}

function catalog(): void
{
    $demo = categoryByUrlKey(DEMO_CATEGORY, ['en-GB' => 'LedgerDirect demo', 'de-DE' => 'LedgerDirect-Demo']);
    $isNew = false;
    $product = productByReference(DEMO_REFERENCE, $isNew);
    $product->price = 1.0;
    $product->name = perLanguage(['en-GB' => 'LedgerDirect demo article', 'de-DE' => 'LedgerDirect-Demo-Artikel']);
    $product->link_rewrite = perLanguage(['en-GB' => 'ledger-direct-demo-article']);
    $product->description = perLanguage([
        'en-GB' => '<p>A demo article for 1 EUR. Pay it with XRP, RLUSD or USDC on the XRP Ledger testnet.</p>',
        'de-DE' => '<p>Ein Demo-Artikel für 1 EUR. Bezahlen Sie ihn mit XRP, RLUSD oder USDC im XRP-Ledger-Testnet.</p>',
    ]);
    saveProduct($product, $demo, $isNew ? [[articlePicture(), ['en-GB' => 'LedgerDirect demo article']]] : []);
    out('category "LedgerDirect demo" with the demo article (reference ' . DEMO_REFERENCE . ', 1 EUR)');

    $miniatures = miniatures();
    homePage($miniatures, $demo);
    Search::indexation(false);
    Tools::clearSmartyCache();
}

/** A category found by its URL key (link_rewrite in English), or created under Home. */
function categoryByUrlKey(string $urlKey, array $names): int
{
    $id = (int) Db::getInstance()->getValue('SELECT id_category FROM ' . table('category_lang')
        . " WHERE link_rewrite = '" . pSQL($urlKey) . "' AND id_lang = " . (int) Language::getIdByIso('en'));
    $category = new Category($id ?: null);
    $category->name = perLanguage($names);
    $category->link_rewrite = perLanguage(['en-GB' => $urlKey]);
    $category->active = true;
    if (!$id) {
        $category->id_parent = (int) Configuration::get('PS_HOME_CATEGORY');
        $category->add();
    } else {
        $category->update();
    }

    return (int) $category->id;
}

/** A product found by its reference, or a new one with the try shop's defaults. */
function productByReference(string $reference, bool &$isNew): Product
{
    $id = (int) Db::getInstance()->getValue('SELECT id_product FROM ' . table('product')
        . " WHERE reference = '" . pSQL($reference) . "'");
    $isNew = $id === 0;
    $product = new Product($id ?: null);
    if ($isNew) {
        $product->reference = $reference;
        // Untaxed, so the article costs exactly its price; shipped, but for free (see shop()).
        $product->id_tax_rules_group = 0;
        $product->weight = 0.1;
        $product->visibility = 'both';
        $product->available_for_order = true;
        $product->show_price = true;
        $product->minimal_quantity = 1;
        $product->state = Product::STATE_SAVED;
    }
    $product->active = true;

    return $product;
}

/**
 * Saves a product into its category with plenty of stock, and adds its pictures:
 * a list of [file, [language key => legend]].
 */
function saveProduct(Product $product, int $category, array $pictures): void
{
    $product->id_category_default = $category;
    $product->save();
    $product->updateCategories([$category]);
    StockAvailable::setQuantity((int) $product->id, 0, 100000);

    foreach ($pictures as $position => [$file, $legend]) {
        $image = new Image();
        $image->id_product = (int) $product->id;
        $image->position = $position + 1;
        $image->cover = $position === 0;
        $image->legend = perLanguage($legend);
        $image->add();
        $path = $image->getPathForCreation();
        ImageManager::resize($file, $path . '.jpg');
        foreach (ImageType::getImagesTypes('products') as $type) {
            ImageManager::resize($file, $path . '-' . stripslashes($type['name']) . '.jpg', (int) $type['width'], (int) $type['height']);
        }
    }
}

/**
 * The miniatures from catalog/catalog.json, in the category "Miniatures". Found again by reference;
 * an existing article gets its name, texts and price from the catalogue, its pictures stay as they are.
 *
 * @return int the category's id
 */
function miniatures(): int
{
    $catalog = json_decode((string) file_get_contents(CATALOG . '/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    $names = [];
    foreach (LANGUAGES as $key) {
        $names[$key] = $catalog['category'][$key]['name'] ?? $catalog['category']['en-GB']['name'];
    }
    $category = categoryByUrlKey($catalog['category']['url_key'], $names);

    foreach ($catalog['products'] as $item) {
        $isNew = false;
        $product = productByReference($item['number'], $isNew);
        $product->price = (float) $item['price'];
        $name = $tagline = $description = [];
        foreach (LANGUAGES as $key) {
            $text = $item[$key] ?? $item['en-GB'];
            $name[$key] = $text['name'];
            $tagline[$key] = '<p>' . htmlspecialchars($text['tagline']) . '</p>';
            $description[$key] = description($text['description'], $item['facts'] ?? [], $catalog['closing'][$key] ?? $catalog['closing']['en-GB']);
        }
        $product->name = perLanguage($name);
        $product->description_short = perLanguage($tagline);
        $product->description = perLanguage($description);
        $product->link_rewrite = perLanguage(['en-GB' => $item['slug']]);

        $pictures = [];
        if ($isNew) {
            foreach ($item['images'] as $image) {
                $pictures[] = [CATALOG . '/images/' . $image['file'], ['en-GB' => $image['alt'] ?? $item['en-GB']['name']]];
            }
        }
        saveProduct($product, $category, $pictures);
        out(sprintf('%s "%s", %.2f EUR%s', $item['number'], $item['en-GB']['name'], $item['price'], $isNew ? '' : ' (updated)'));
    }

    return $category;
}

function description(string $text, array $facts, string $closing): string
{
    $paragraphs = array_map(
        static fn (string $line): string => '<p>' . htmlspecialchars($line) . '</p>',
        array_values(array_filter(array_map('trim', explode("\n", $text))))
    );
    $list = array_map(
        static fn (string $key, string $value): string => '<li>' . ucfirst($key) . ': ' . htmlspecialchars($value) . '</li>',
        array_keys($facts),
        array_values($facts)
    );

    return implode("\n", $paragraphs)
        . ($list ? "\n<ul>" . implode('', $list) . '</ul>' : '')
        . "\n<p><em>" . htmlspecialchars($closing) . '</em></p>';
}

/** A picture for the demo article, drawn here: no image file with a licence of its own in the image. */
function articlePicture(): string
{
    $size = 800;
    $image = imagecreatetruecolor($size, $size);
    $accent = imagecolorallocate($image, 0x1F, 0x5E, 0xFF);
    $white = imagecolorallocate($image, 0xFF, 0xFF, 0xFF);
    imagefilledrectangle($image, 0, 0, $size, $size, $accent);
    $font = '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf';
    $lines = [['LedgerDirect', 64, 330], ['demo article', 44, 420], ['1 EUR · XRP Ledger testnet', 26, 500]];
    foreach ($lines as [$text, $points, $y]) {
        if (is_file($font) && function_exists('imagettftext')) {
            $box = imagettfbbox($points, 0, $font, $text);
            imagettftext($image, $points, 0, (int) (($size - ($box[2] - $box[0])) / 2), $y, $white, $font, $text);
        } else {
            imagestring($image, 5, (int) (($size - 9 * strlen($text)) / 2), $y - 15, $text, $white);
        }
    }
    $path = sys_get_temp_dir() . '/ledger-direct-demo-article.png';
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

/**
 * The home page: the explanation (ps_customtext) above the miniatures (ps_featuredproducts, set to
 * the Miniatures category), the main menu with the two categories.
 */
function homePage(int $miniatures, int $demo): void
{
    Configuration::updateValue('HOME_FEATURED_CAT', $miniatures);
    Configuration::updateValue('HOME_FEATURED_NBR', 12);
    Configuration::updateValue('HOME_FEATURED_RANDOMIZE', 0);
    Configuration::updateValue('MOD_BLOCKTOPMENU_ITEMS', 'CAT' . $miniatures . ',CAT' . $demo);

    $texts = [
        'en-GB' => <<<'HTML'
<h2>Pay with LedgerDirect</h2>
<p><strong>A demo shop on the XRP Ledger testnet.</strong> It runs <a href="https://github.com/ledger-direct/ledger-direct-prestashop">LedgerDirect for PrestaShop</a>:
customers pay with XRP, RLUSD or USDC straight into the shop's own wallet — no payment provider in between.
Nothing here costs real money, and no order is shipped.</p>
<ol>
<li>Put a miniature in the cart and check out as a guest — any name and address will do.</li>
<li>Choose <em>Pay with XRP</em> (or RLUSD or USDC) and place the order.</li>
<li>The payment page shows the amount, the shop's account and a destination tag. Pay it from a testnet wallet,
with the destination tag, or connect the wallet with the buttons on the page. The page notices the payment within seconds.</li>
</ol>
<p>A testnet wallet: <a href="https://xaman.app/">Xaman</a> in testnet mode or <a href="https://crossmark.io/">Crossmark</a>,
with XRP from the <a href="https://xrpl.org/resources/dev-tools/xrp-faucets">XRP testnet faucet</a>. RLUSD and USDC come from the
<a href="https://tryrlusd.com/">RLUSD faucet</a> and the <a href="https://faucet.circle.com/">Circle faucet</a>.</p>
HTML,
        'de-DE' => <<<'HTML'
<h2>Bezahlen mit LedgerDirect</h2>
<p><strong>Ein Demo-Shop im XRP-Ledger-Testnet.</strong> Er läuft mit <a href="https://github.com/ledger-direct/ledger-direct-prestashop">LedgerDirect für PrestaShop</a>:
Kunden zahlen mit XRP, RLUSD oder USDC direkt in die eigene Wallet des Shops — ohne Zahlungsdienstleister dazwischen.
Hier kostet nichts echtes Geld, und keine Bestellung wird versendet.</p>
<ol>
<li>Legen Sie eine Miniatur in den Warenkorb und bestellen Sie als Gast — Name und Adresse sind beliebig.</li>
<li>Wählen Sie <em>Mit XRP bezahlen</em> (oder RLUSD oder USDC) und schicken Sie die Bestellung ab.</li>
<li>Die Zahlungsseite zeigt den Betrag, das Konto des Shops und einen Destination Tag. Bezahlen Sie aus einer Testnet-Wallet,
mit dem Destination Tag, oder verbinden Sie die Wallet über die Knöpfe auf der Seite. Die Seite bemerkt die Zahlung innerhalb von Sekunden.</li>
</ol>
<p>Eine Testnet-Wallet: <a href="https://xaman.app/">Xaman</a> im Testnet-Modus oder <a href="https://crossmark.io/">Crossmark</a>,
mit XRP vom <a href="https://xrpl.org/resources/dev-tools/xrp-faucets">XRP-Testnet-Faucet</a>. RLUSD und USDC gibt es beim
<a href="https://tryrlusd.com/">RLUSD-Faucet</a> und beim <a href="https://faucet.circle.com/">Circle-Faucet</a>.</p>
HTML,
    ];
    $db = Db::getInstance();
    $rows = $db->executeS('SELECT * FROM ' . table('info_lang') . ' WHERE id_lang = ' . (int) Language::getIdByIso('en')) ?: [];
    foreach ($rows as $row) {
        foreach (languages() as $id => $key) {
            $db->insert('info_lang', ['id_lang' => $id, 'text' => pSQL($texts[$key], true)] + $row, false, true, Db::REPLACE);
        }
    }
}

match ($argv[1] ?? '') {
    'shop' => shop(),
    'config' => configure(),
    'catalog' => catalog(),
    default => throw new InvalidArgumentException('usage: install.php shop|config|catalog'),
};
