<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\Fees\FeeCalculator;
use App\Support\Ledger\LedgerRecorder;
use App\Support\Notifications\PayerNotifier;
use App\Support\ProviderFees;

/**
 * Keeps every transaction's fee breakdown (collection fee, settlement fee, net
 * amount) in sync so payments are auditable end-to-end regardless of which
 * provider handled them. The collection fee uses the provider's ACTUAL figure
 * when it returns one in the charge response, otherwise the schedule estimate.
 * Once saved, the transaction's ledger entries are synced to its state, and
 * the payer is told when a refund completes.
 */
class TransactionObserver
{
    /**
     * Recompute the fee breakdown before each write.
     */
    public function saving(Transaction $transaction): void
    {
        $amount = (float) $transaction->amount;
        if ($amount <= 0) {
            return;
        }

        $provider = $transaction->paymentProvider;
        if (! $provider) {
            return;
        }

        $currency = $transaction->currency;
        $schedule = ProviderFees::scheduleFor($provider);

        if ($transaction->direction === 'debit') {
            $this->priceDebit($transaction, $schedule, $amount);

            return;
        }

        $actual = ProviderFees::actualCollectionFee($provider->class, $transaction->provider_response, $currency);

        $collection = $actual ?? ($schedule ? ProviderFees::collectionFee($schedule, $amount, $currency) : null);
        $settlement = $schedule ? ProviderFees::settlementFee($schedule, $amount, $currency) : null;

        // No fee data for this provider — the net is simply the gross.
        if ($collection === null && $settlement === null) {
            $transaction->net_amount = round($amount, 4);
            $transaction->fee_estimated = false;

            return;
        }

        $transaction->collection_fee = $collection;
        $transaction->settlement_fee = $settlement;
        $transaction->net_amount = FeeCalculator::money($transaction->amount)
            ->minus(FeeCalculator::money((float) $collection))
            ->minus(FeeCalculator::money((float) $settlement))
            ->toFloat();
        // The collection fee is estimated unless the provider reported the actual.
        $transaction->fee_estimated = $actual === null;
    }

    /**
     * Keep the ledger in step with the transaction's settled state, and move
     * any cross-border transfer the transaction belongs to.
     */
    public function saved(Transaction $transaction): void
    {
        app(LedgerRecorder::class)->sync($transaction);
        app(CrossBorderTransferService::class)->transactionChanged($transaction);

        if ($transaction->refund_of_id !== null && ($transaction->wasChanged('status') || $transaction->wasRecentlyCreated)) {
            app(PayerNotifier::class)->refundCompleted($transaction);
        }
    }

    /**
     * A payout: the provider fee is charged on top, so `net_amount` is the
     * total taken from the float (amount + fee) and the recipient gets the amount.
     *
     * @param  array<string, mixed>|null  $schedule
     */
    private function priceDebit(Transaction $transaction, ?array $schedule, float $amount): void
    {
        $fee = $schedule ? ProviderFees::payoutFee($schedule, $amount, $transaction->currency) : null;

        $transaction->collection_fee = $fee;
        $transaction->settlement_fee = null;
        $transaction->net_amount = FeeCalculator::money($transaction->amount)
            ->plus(FeeCalculator::money((float) $fee))
            ->toFloat();
        $transaction->fee_estimated = $fee !== null;
    }
}
