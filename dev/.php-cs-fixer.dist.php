<?php

declare(strict_types=1);

/**
 * PrestaShop's coding standard, applied to the module.
 *
 * The rule set comes from prestashop/php-dev-tools, the same one PrestaShop's
 * own modules use. Run from the repository root:
 *
 *   dev/vendor/bin/php-cs-fixer fix --config=dev/.php-cs-fixer.dist.php --dry-run --diff
 */
$config = new PrestaShop\CodingStandards\CsFixer\Config();
$config
    ->setUsingCache(false)
    ->getFinder()
    ->in(dirname(__DIR__))
    ->exclude(['vendor', 'dev/vendor', 'dev/.phpunit.cache'])
    ->notPath('tests/phpstan-prestashop-stubs.php');

return $config;
