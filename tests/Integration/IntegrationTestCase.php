<?php

declare(strict_types=1);

namespace LedgerDirect\Tests\Integration;

use Customer;
use PHPUnit\Framework\TestCase;

/**
 * Boots a real PrestaShop for the tests that need one.
 *
 * Runs only inside the container — these exercise the actual database and the
 * actual ObjectModel behaviour, which is the entire point: every bug this
 * suite was written for came from PrestaShop doing something the adapter did
 * not expect, not from the adapter's own arithmetic. The unit suite covers
 * what can be checked without any of that.
 */
abstract class IntegrationTestCase extends TestCase
{
    private const PS_ROOT = '/var/www/html';

    private static bool $booted = false;

    public static function setUpBeforeClass(): void
    {
        self::bootPrestaShop();
    }

    protected static function bootPrestaShop(): void
    {
        if (self::$booted) {
            return;
        }

        if (!is_file(self::PS_ROOT . '/config/config.inc.php')) {
            throw new \RuntimeException('The integration suite needs a PrestaShop installation at ' . self::PS_ROOT . '. Run it inside the container: docker compose exec -u www-data prestashop php modules/ledgerdirect/vendor/bin/phpunit --testsuite integration');
        }

        // Legacy bootstrap first — it defines _PS_ROOT_DIR_, which the kernel
        // needs. The kernel in turn is what validateOrder() reaches for via
        // Tools::getContextLocale().
        require_once self::PS_ROOT . '/config/config.inc.php';
        require_once self::PS_ROOT . '/app/AppKernel.php';
        require_once self::PS_ROOT . '/app/FrontKernel.php';

        $kernel = new \FrontKernel('dev', true);
        $kernel->boot();

        $context = \Context::getContext();
        $context->container = $kernel->getContainer();
        $context->link = new \Link();
        $context->language = new \Language((int) \Configuration::get('PS_LANG_DEFAULT'));
        $context->currency = new \Currency((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $context->country = new \Country((int) \Configuration::get('PS_COUNTRY_DEFAULT'));
        $context->customer = new \Customer(self::customerId());

        self::$booted = true;
    }

    /**
     * The demo customer that ships with PrestaShop's fixtures, and the address
     * that belongs to them.
     */
    protected static function customerId(): int
    {
        return 2;
    }

    protected static function addressId(): int
    {
        return 2;
    }
}
