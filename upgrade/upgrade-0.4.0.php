<?php

/**
 * 0.3.0 -> 0.4.0: the "XRPL payment incomplete" order state and the
 * LedgerDirect panel on the Back Office order page.
 *
 * The state is created through the same Installer method a fresh install
 * uses, so both paths end up with the same configuration.
 */
use LedgerDirect\Install\Installer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Ledgerdirect $module
 */
function upgrade_module_0_4_0($module): bool
{
    return Installer::ensureOrderStates($module->name)
        && $module->registerHook('displayAdminOrderSide');
}
