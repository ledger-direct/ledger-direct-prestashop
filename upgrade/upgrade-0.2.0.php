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
 * 0.1.0 -> 0.2.0: the `network` column on the synced-transaction table,
 * required by core 0.4's per-network sync cursor.
 *
 * PrestaShop runs this once when it finds the installed version below the
 * one the module class declares. The work itself lives in the Installer so
 * a fresh install and a reinstall over kept tables end up with the same
 * schema through the same code.
 */
use LedgerDirect\Install\Installer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Ledgerdirect $module
 */
function upgrade_module_0_2_0($module): bool
{
    return Installer::ensureSchema();
}
