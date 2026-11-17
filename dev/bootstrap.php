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

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * Two autoloaders from two separate Composer projects: this directory's, which
 * has the test tooling and the LedgerDirect\Tests\ namespace, and the
 * module's, which has the runtime dependencies.
 *
 * They are separate on purpose. PrestaShop loads the module's
 * vendor/autoload.php on every request, so anything installed there wins over
 * the shop's own copy of the same package — which is how PHPUnit's transitive
 * nikic/php-parser v5 once shadowed the v4 that PrestaShop's Module Manager
 * calls and took the whole Back Office down. dev/vendor/ is never autoloaded
 * by PrestaShop, so the tooling can live here without that risk.
 */
require_once __DIR__ . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Every module file exits unless PrestaShop is loaded, which would end a test
// run silently with exit code 0. The unit suite runs without a shop and
// defines _PS_VERSION_ itself. The integration suite runs inside the shop and
// loads it here, before PHPUnit reads the test classes: some of them name
// module classes in constants. PrestaShop defines the constant without
// checking, so the unit suite's definition must not come first. A test that
// runs in a separate process gets no command line, only the environment, so
// the suite is passed on there.
if (preg_grep('/integration/', $_SERVER['argv'] ?? []) !== []) {
    putenv('LEDGERDIRECT_TEST_SUITE=integration');
}
if (getenv('LEDGERDIRECT_TEST_SUITE') === 'integration') {
    $config = '/var/www/html/config/config.inc.php';
    if (!is_file($config)) {
        fwrite(STDERR, "The integration suite needs a PrestaShop installation at /var/www/html. Run it inside the container.\n");
        exit(1);
    }
    require_once $config;
} else {
    define('_PS_VERSION_', '9.0.0');
}
