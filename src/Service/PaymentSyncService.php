<?php

declare(strict_types=1);

namespace LedgerDirect\Service;

use Hardcastle\LedgerDirect\Core\Payment\PaymentIntent;
use LedgerDirect\Install\Installer;
use LedgerDirect\Port\PrestaShopConfigProvider;
use LedgerDirect\Storage\OrderPaymentIntentRepository;
use Order;

/**
 * Pulls the merchant's incoming XRPL transactions and settles the orders they
 * pay for.
 *
 * Sync and match are one pass but two responsibilities: the core's SyncService
 * fills the local transaction table from the ledger, and this class decides
 * what that means for a PrestaShop order. Nothing here re-implements ledger
 * access or amount decoding — that is the core's job.
 */
final class PaymentSyncService
{
    public function __construct(
        private readonly ServiceFactory $services,
        private readonly OrderPaymentIntentRepository $intents,
    ) {
    }

    public static function create(): self
    {
        return new self(ServiceFactory::getInstance(), new OrderPaymentIntentRepository());
    }

    /**
     * Syncs the ledger once, then tries to settle every order still waiting.
     *
     * @return array{synced: bool, checked: int, settled: int}
     */
    public function syncAndMatchAll(): array
    {
        $synced = $this->syncLedger();

        $checked = 0;
        $settled = 0;

        foreach ($this->findAwaitingOrderIds() as $orderId) {
            ++$checked;
            if ($this->matchOrder($orderId)) {
                ++$settled;
            }
        }

        return ['synced' => $synced, 'checked' => $checked, 'settled' => $settled];
    }

    /**
     * The single-order path used by the payment page while a customer waits.
     * Syncs first, because the whole point is to notice a transaction that
     * landed seconds ago.
     */
    public function syncAndMatchOrder(int $orderId): bool
    {
        $this->syncLedger(true);

        return $this->matchOrder($orderId);
    }

    /**
     * @return bool true when the order is now settled
     */
    public function matchOrder(int $orderId): bool
    {
        $order = new \Order($orderId);
        if (!\Validate::isLoadedObject($order) || !self::isAwaitingPayment($order)) {
            return false;
        }

        try {
            $paymentIntent = $this->intents->find($orderId);
        } catch (\Throwable $exception) {
            $this->services->getLogger()->error('Cannot match order, its payment intent is unreadable', [
                'id_order' => $orderId,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($paymentIntent === null) {
            return false;
        }

        $policy = $this->services->getSettlementPolicy();

        // A stored fulfillment ends the matching only if it settled the order.
        // That still covers the window this guard was written for — save()
        // succeeded, setCurrentState() threw — without letting the first wrong
        // or short payment lock out every later one: the customer may still
        // send the right token, or the rest of the amount.
        if ($paymentIntent->hash !== null && $policy->isSettled($paymentIntent)) {
            return false;
        }

        // Which transactions on the tag pay, and what they add up to — the
        // core's decision. Every payment in the quoted asset counts, so a
        // top-up of the shortfall settles; a payment in another asset is the
        // fulfillment only while nothing in the right one has arrived, so the
        // page can say "wrong token". Non-payments, unreadable amounts and the
        // other asset class are skipped and logged there.
        $fulfilled = $this->services->getSyncService()->findFulfillmentFor($paymentIntent)?->applyTo($paymentIntent);
        if ($fulfilled === null) {
            return false;
        }

        if (!$policy->isSettled($fulfilled)) {
            // Persist the attempt: PaymentStatus is derived from the stored
            // intent, so without this the payment page would keep showing
            // "waiting" to a customer whose money is already on the ledger.
            // Only when something changed — the poll runs every few seconds.
            if (self::fulfillmentChanged($paymentIntent, $fulfilled)) {
                $this->intents->save($orderId, $fulfilled);
                $this->markIncomplete($order);

                $this->services->getLogger()->warning('Payment does not settle the order', [
                    'id_order' => $orderId,
                    'hash' => $fulfilled->hash,
                    'result' => $policy->isWrongAsset($fulfilled) ? 'wrong_asset' : 'underpaid',
                    'requested' => $fulfilled->amountRequested,
                    'delivered' => $fulfilled->amountPaid,
                    'shortfall' => $policy->shortfall($fulfilled),
                ]);
            }

            return false;
        }

        return $this->settle($order, $fulfilled);
    }

    private static function fulfillmentChanged(PaymentIntent $before, PaymentIntent $after): bool
    {
        return $before->hash !== $after->hash || $before->amountPaid !== $after->amountPaid;
    }

    /**
     * Moves a waiting order to "XRPL payment incomplete" the first time
     * something arrives that does not pay it. That is what the merchant sees:
     * the order list, the status filter, the history line with a timestamp.
     * The details (what arrived, what is missing, which transaction) are on
     * the LedgerDirect panel of the order page. The state is still an open
     * one — matching continues, and the paid state follows once the ledger
     * covers the amount.
     */
    private function markIncomplete(\Order $order): void
    {
        $incompleteStateId = Installer::getIncompleteOrderStateId();

        if ($incompleteStateId <= 0 || (int) $order->getCurrentState() === $incompleteStateId) {
            return;
        }

        try {
            $order->setCurrentState($incompleteStateId);
        } catch (\Throwable $exception) {
            // The intent is saved either way; the state is a courtesy to the
            // merchant, not something the settlement depends on.
            $this->services->getLogger()->warning('Could not mark the order as incompletely paid', [
                'id_order' => (int) $order->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param PaymentIntent $fulfilled the intent with hash, ctid and delivered amount set
     */
    private function settle(\Order $order, PaymentIntent $fulfilled): bool
    {
        $orderId = (int) $order->id;
        $paidStateId = (int) \Configuration::get('PS_OS_PAYMENT');
        $hash = (string) $fulfilled->hash;
        $ctid = $fulfilled->ctid;

        // Record the fulfillment first: if anything below fails, the next run
        // sees hash !== null and stops rather than crediting the transaction
        // twice.
        $this->intents->save($orderId, $fulfilled);

        try {
            $order->setCurrentState($paidStateId);
        } catch (\Throwable $exception) {
            // setCurrentState() changes the state and *then* sends the
            // confirmation email. A dead mail server therefore throws after
            // the order has already moved — treating that as failure would
            // report a settled order as unsettled and invite a second attempt.
            $this->services->getLogger()->warning('Order state changed but the notification failed', [
                'id_order' => $orderId,
                'hash' => $hash,
                'exception' => $exception->getMessage(),
            ]);
        }

        // Report on the state that actually stuck, not on whether every side
        // effect along the way succeeded. Read straight from storage with the
        // query cache bypassed: re-loading the Order object can hand back the
        // row as this request first saw it, which would report a settled order
        // as unsettled and invite a second attempt.
        $settled = self::readCurrentState($orderId) === $paidStateId;

        if (!$settled) {
            $this->services->getLogger()->error('Payment recorded but the order did not reach the paid state', [
                'id_order' => $orderId,
                'hash' => $hash,
            ]);

            return false;
        }

        $this->recordTransactionHash($order, $hash);

        $this->services->getLogger()->info('Order settled on the XRP Ledger', [
            'id_order' => $orderId,
            'hash' => $hash,
            'ctid' => $ctid,
        ]);

        return true;
    }

    /**
     * Puts the on-chain transaction hash on the order's payment record.
     *
     * Deliberately *after* the state change and without creating a record of
     * our own. Moving an order into a paid state makes PrestaShop write its
     * own OrderPayment for whatever the invoice still has outstanding, and
     * link it to that invoice. A record we add beforehand is not linked, so
     * PrestaShop still sees the full amount as unpaid and books a second one —
     * leaving the merchant looking at an order paid twice (total_paid_real of
     * double the order total, which is exactly what happened before this was
     * turned around).
     *
     * So: let PrestaShop do the accounting, then stamp the hash onto what it
     * wrote, so a merchant can still look the payment up on-chain.
     */
    private function recordTransactionHash(\Order $order, string $hash): void
    {
        try {
            $payments = $order->getOrderPayments();

            if ($payments === []) {
                // Nothing was booked — a paid state that raises no invoice.
                // Then the record really is ours to write.
                $order->addOrderPayment((string) (float) $order->total_paid, $order->payment, $hash);

                return;
            }

            foreach ($payments as $payment) {
                if ((string) $payment->transaction_id !== '') {
                    continue;
                }

                $payment->transaction_id = $hash;
                $payment->payment_method = $order->payment;
                $payment->save();
            }
        } catch (\Throwable $exception) {
            // Bookkeeping detail: the order is already settled and the hash is
            // on the PaymentIntent either way, so this never fails the match.
            $this->services->getLogger()->warning('Could not attach the transaction hash to the payment record', [
                'id_order' => (int) $order->id,
                'hash' => $hash,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private static function readCurrentState(int $orderId): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `current_state` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . $orderId,
            false
        );
    }

    /**
     * @param bool $throttled skip the node request when this account was synced
     *                        within the core SyncThrottle's interval — the poll
     *                        and the check button pass true, the cron never
     *                        does: it is the safety net, token-protected, on
     *                        its own schedule. shouldSync()/markSynced() rather
     *                        than syncIfDue(): the mark is set *before* the
     *                        request, so a node that is down is not hit harder
     *                        than one that answers
     *
     * @return bool whether the ledger was synced in this call
     */
    private function syncLedger(bool $throttled = false): bool
    {
        $configProvider = $this->services->getConfigProvider();
        $destinationAccount = $configProvider->getDestinationAccount(PrestaShopConfigProvider::CHAIN_XRPL);
        $network = $configProvider->getNetwork(PrestaShopConfigProvider::CHAIN_XRPL);

        if ($destinationAccount === '') {
            return false;
        }

        $throttle = $this->services->getSyncThrottle();

        if ($throttled && !$throttle->shouldSync($network, $destinationAccount)) {
            return false;
        }

        // Marked before the request, not after a successful one: a node that
        // is down must not be hit harder than one that answers.
        if ($throttled) {
            $throttle->markSynced($network, $destinationAccount);
        }

        try {
            $this->services->getSyncService()->syncTransactions($destinationAccount, $network);
        } catch (\Throwable $exception) {
            // A node that is down must not take the checkout with it: matching
            // still runs against whatever is already stored locally.
            $this->services->getLogger()->error('Ledger sync failed', [
                'destination_account' => $destinationAccount,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Whether the order is still open for payment on the ledger: nothing
     * arrived yet, or something did that does not cover the amount. Both
     * states are matched, both render the payment page.
     */
    public static function isAwaitingPayment(\Order $order): bool
    {
        return in_array((int) $order->getCurrentState(), Installer::openOrderStateIds(), true);
    }

    /**
     * The same question, answered from storage with the query cache bypassed.
     * An Order object loaded before a sync still reports the state it was
     * loaded with — a poll that settled the order in this very request would
     * otherwise not notice until the next one.
     */
    public static function isAwaitingPaymentById(int $orderId): bool
    {
        return in_array(self::readCurrentState($orderId), Installer::openOrderStateIds(), true);
    }

    /**
     * @return int[]
     */
    private function findAwaitingOrderIds(): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT o.`id_order`
               FROM `' . _DB_PREFIX_ . 'orders` o
               INNER JOIN `' . _DB_PREFIX_ . Installer::TABLE_ORDER_PAYMENT_INTENT . '` i
                       ON i.`id_order` = o.`id_order`
              WHERE o.`current_state` IN (' . implode(',', Installer::openOrderStateIds()) . ')
              ORDER BY o.`id_order` ASC'
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['id_order'], $rows);
    }
}
