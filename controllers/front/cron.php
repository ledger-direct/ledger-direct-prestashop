<?php

use LedgerDirect\Port\PrestaShopConfigProvider;
use LedgerDirect\Service\PaymentSyncService;

if (!defined('_PS_VERSION_')) { exit; }

/**
 * The safety net: syncs the ledger and settles every waiting order.
 *
 * A front controller rather than a console command because plenty of shops
 * have no CLI access — a merchant can point any HTTP cron service at this URL.
 * It is reachable without a session, so the token is the only thing guarding
 * it.
 */
class LedgerdirectCronModuleFrontController extends ModuleFrontController
{
    /** No theme, no cart, no customer — this is a machine endpoint. */
    public $content_only = true;
    public $ssl = true;

    public function postProcess()
    {
        $expected = PrestaShopConfigProvider::getCronToken();
        $provided = (string) Tools::getValue('token');

        // Constant-time, and an unset token never authorises: without this
        // guard a fresh install with no token would accept an empty one.
        if ($expected === '' || !hash_equals($expected, $provided)) {
            header('HTTP/1.1 403 Forbidden');
            $this->ajaxRender(json_encode(['error' => 'forbidden']));
            exit;
        }

        $result = PaymentSyncService::create()->syncAndMatchAll();

        $this->ajaxRender(json_encode($result));
        exit;
    }
}
