<?php

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
