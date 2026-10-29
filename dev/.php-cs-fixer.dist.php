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
// PrestaShop's Config class hard-codes its rule set in getRules(), so the header rule cannot be
// added through setRules() on it; the rules are taken from it and put on a plain Config instead.
$prestashopRules = (new PrestaShop\CodingStandards\CsFixer\Config())->getRules();
$config = (new PhpCsFixer\Config('PrestaShop coding standard'))->setRiskyAllowed(true);

// Addons wants a licence header in every PHP file (the index.php guards included); the module is
// MIT, so the header names that licence and the module's copyright, not PrestaShop's AFL block.
$header = <<<'HEADER'
LedgerDirect for PrestaShop

Copyright (c) 2026 Hardcastle
This source file is subject to the MIT license that is bundled with this module in LICENSE.md
and available at https://opensource.org/licenses/MIT.

@author    Hardcastle <https://www.ledger-direct.com>
@copyright 2026 Hardcastle
@license   https://opensource.org/licenses/MIT MIT License
HEADER;

$config
    ->setRules($prestashopRules + [
        'header_comment' => [
            'header' => $header,
            'comment_type' => 'PHPDoc',
            'location' => 'after_open',
            'separate' => 'both',
        ],
    ])
    ->setUsingCache(false)
    ->getFinder()
    ->in(dirname(__DIR__))
    ->exclude(['vendor', 'dev/vendor', 'dev/.phpunit.cache'])
    ->notPath('tests/phpstan-prestashop-stubs.php');

return $config;
