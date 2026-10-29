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
 * 0.4.0 -> 0.5.0: the redesigned payment page.
 *
 * Two things a fresh install does that an upgrade has to catch up on: the
 * hook that lets the page render without the theme's header and footer,
 * and the defaults of the new "Payment page" settings (logo, accent colour,
 * wallet-app identifiers). Existing settings are not touched.
 */
use LedgerDirect\Install\Installer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Ledgerdirect $module
 */
function upgrade_module_0_5_0($module): bool
{
    return $module->registerHook('overrideLayoutTemplate')
        && Installer::ensureConfigurationDefaults();
}
