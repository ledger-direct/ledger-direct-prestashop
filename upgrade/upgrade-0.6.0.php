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
 * 0.5.x -> 0.6.0: Xahau as the second chain (core 0.11).
 *
 * A fresh install creates Xahau's two tables and writes the defaults of the
 * new "Xahau settings"; an upgrade has to catch up on both. The XRP Ledger's
 * tables and settings are not touched.
 */
use LedgerDirect\Install\Installer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Ledgerdirect $module
 */
function upgrade_module_0_6_0($module): bool
{
    return Installer::ensureSchema()
        && Installer::ensureConfigurationDefaults();
}
