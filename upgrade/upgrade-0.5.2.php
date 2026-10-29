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
 * 0.5.1 -> 0.5.2: the CTID column is `tx_ctid` (core 0.9).
 *
 * PostgreSQL reserves `ctid` as a system column name, so the core fixes
 * `tx_ctid` for every adapter. The rename lives in the Installer so a fresh
 * install, a reinstall over kept tables and this upgrade end up with the
 * same schema through the same code.
 */
use LedgerDirect\Install\Installer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Ledgerdirect $module
 */
function upgrade_module_0_5_2($module): bool
{
    return Installer::ensureSchema();
}
