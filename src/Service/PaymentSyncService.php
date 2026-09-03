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
        $this->syncLedger();

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

        if ($paymentIntent === null || $paymentIntent->hash !== null) {
            return false;
        }

        $transaction = $this->services->getSyncService()->findTransaction(
            $paymentIntent->destinationAccount,
            $paymentIntent->destinationTag
        );

        if ($transaction === null) {
            return false;
        }

        try {
            $delivered = $transaction->getDeliveredAmount();
        } catch (\Throwable $exception) {
            // Includes the ledger's "unavailable" marker: money arrived but
            // the amount cannot be reconstructed. Never settle on a guess —
            // this is a case for a human.
            $this->services->getLogger()->error('Matched a transaction with an unusable delivered amount', [
                'id_order' => $orderId,
                'hash' => $transaction->hash,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($delivered === null) {
            // Not a Payment — an EscrowCreate or CheckCreate also carries a
            // destination and tag and lands in the same table.
            $this->services->getLogger()->warning('Transaction matched the tag but delivered nothing', [
                'id_order' => $orderId,
                'hash' => $transaction->hash,
            ]);

            return false;
        }

        $result = AmountMatcher::evaluate($paymentIntent, $delivered);

        if ($result !== AmountMatcher::RESULT_SETTLED) {
            $this->services->getLogger()->warning('Payment does not settle the order', [
                'id_order' => $orderId,
                'hash' => $transaction->hash,
                'result' => $result,
                'requested' => $paymentIntent->amountRequested,
                'delivered' => $delivered,
            ]);

            return false;
        }

        return $this->settle($order, $paymentIntent, $transaction->hash, $transaction->ctid, $delivered);
    }

    /**
     * @param float|array<string, mixed> $delivered
     */
    private function settle(
        \Order $order,
        PaymentIntent $paymentIntent,
        string $hash,
        string $ctid,
        float|array $delivered,
    ): bool {
        $orderId = (int) $order->id;
        $paidStateId = (int) \Configuration::get('PS_OS_PAYMENT');

        $fulfilled = $paymentIntent->withFulfillment($hash, $delivered, $ctid);

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
        $settled = $this->readCurrentState($orderId) === $paidStateId;

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

    private function readCurrentState(int $orderId): int
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `current_state` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . $orderId,
            false
        );
    }

    private function syncLedger(): bool
    {
        $configProvider = $this->services->getConfigProvider();
        $destinationAccount = $configProvider->getDestinationAccount(PrestaShopConfigProvider::CHAIN_XRPL);

        if ($destinationAccount === '') {
            return false;
        }

        try {
            $this->services->getSyncService()->syncTransactions(
                $destinationAccount,
                $configProvider->getNetwork(PrestaShopConfigProvider::CHAIN_XRPL)
            );
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

    public static function isAwaitingPayment(\Order $order): bool
    {
        return (int) $order->getCurrentState() === Installer::getOrderStateId();
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
              WHERE o.`current_state` = ' . Installer::getOrderStateId() . '
              ORDER BY o.`id_order` ASC'
        );

        if (!is_array($rows)) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['id_order'], $rows);
    }
}
