<?php

declare(strict_types=1);

namespace LedgerDirect\Admin;

use Hardcastle\LedgerDirect\Core\Payment\PaymentStatus;
use Hardcastle\LedgerDirect\Core\Xrpl\XrplTransaction;
use LedgerDirect\Presentation\PaymentIntentPresenter;
use LedgerDirect\Service\ServiceFactory;
use LedgerDirect\Storage\OrderPaymentIntentRepository;

/**
 * The LedgerDirect block on the Back Office order page.
 *
 * What the merchant could not see before: the payment state in the core's
 * five words, what was asked for, what arrived, what is still missing, and
 * every transaction on the order's destination tag with a link to the
 * explorer. The order state "XRPL payment incomplete" says *that* something
 * is wrong; this says *what*.
 *
 * Read-only on purpose. Nothing here changes the order — settling is the
 * sync's job, and a merchant who wants to accept a short payment uses the
 * order status like for any other payment method.
 */
final class OrderPanel
{
    private const DOMAIN = 'Modules.Ledgerdirect.Admin';

    /** Seconds between the Unix epoch and the Ripple epoch (2000-01-01). */
    private const RIPPLE_EPOCH_OFFSET = 946684800;

    private const EXPLORER = [
        'mainnet' => 'https://livenet.xrpl.org/transactions/',
        'testnet' => 'https://testnet.xrpl.org/transactions/',
    ];

    public function __construct(private readonly \Ledgerdirect $module)
    {
    }

    public function render(int $orderId): string
    {
        if ($orderId <= 0) {
            return '';
        }

        try {
            $paymentIntent = (new OrderPaymentIntentRepository())->find($orderId);
        } catch (\Throwable $exception) {
            ServiceFactory::getInstance()->getLogger()->error('Could not render the order panel', [
                'id_order' => $orderId,
                'exception' => $exception->getMessage(),
            ]);

            return '';
        }

        // Not one of ours — every other payment module's order.
        if ($paymentIntent === null) {
            return '';
        }

        $services = ServiceFactory::getInstance();
        $view = PaymentIntentPresenter::present($paymentIntent, $services->getSettlementPolicy());

        $transactions = [];
        try {
            foreach ($services->getSyncService()->findTransactions($paymentIntent->destinationAccount, $paymentIntent->destinationTag) as $transaction) {
                $transactions[] = $this->presentTransaction($transaction);
            }
        } catch (\Throwable $exception) {
            // The panel still shows the intent; the list is a bonus.
            $services->getLogger()->warning('Could not list the transactions for the order panel', [
                'id_order' => $orderId,
                'exception' => $exception->getMessage(),
            ]);
        }

        \Context::getContext()->smarty->assign([
            'ld_panel' => $view,
            'ld_state_label' => $this->stateLabel($view['state']),
            'ld_transactions' => $transactions,
            'ld_explorer' => self::EXPLORER[$paymentIntent->network] ?? null,
        ]);

        return $this->module->fetch('module:ledgerdirect/views/templates/hook/admin_order_side.tpl');
    }

    /**
     * @return array{hash: string, delivered: string|null, is_issued: bool, date: string}
     */
    private function presentTransaction(XrplTransaction $transaction): array
    {
        try {
            $delivered = $transaction->getDeliveredAmount();
        } catch (\UnexpectedValueException) {
            $delivered = null;
        }

        return [
            'hash' => $transaction->hash,
            'delivered' => $delivered === null ? null : PaymentIntentPresenter::plainAmount($delivered),
            // Issued currencies carry their code as a 40-hex string on the
            // ledger (e.g. RLUSD); the merchant reads the asset from the state
            // label and the request, not from that.
            'is_issued' => is_array($delivered),
            'date' => date('Y-m-d H:i:s', self::RIPPLE_EPOCH_OFFSET + $transaction->date),
        ];
    }

    private function stateLabel(string $state): string
    {
        $translator = $this->module->getTranslator();

        return match ($state) {
            PaymentStatus::SETTLED => $translator->trans('Paid', [], self::DOMAIN),
            PaymentStatus::PARTIAL => $translator->trans('Partially paid', [], self::DOMAIN),
            PaymentStatus::WRONG_ASSET => $translator->trans('Paid in the wrong token — not credited', [], self::DOMAIN),
            PaymentStatus::EXPIRED => $translator->trans('Quote expired, nothing received', [], self::DOMAIN),
            default => $translator->trans('Waiting for payment', [], self::DOMAIN),
        };
    }
}
