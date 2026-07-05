<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Support\ProviderFees;

/**
 * Keeps every transaction's fee breakdown (collection fee, settlement fee, net
 * amount) in sync so payments are auditable end-to-end regardless of which
 * provider handled them. The collection fee uses the provider's ACTUAL figure
 * when it returns one in the charge response, otherwise the schedule estimate.
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

        $schedule = ProviderFees::scheduleFor($provider);
        $actual = ProviderFees::actualCollectionFee($provider->class, $transaction->provider_response);

        $collection = $actual ?? ($schedule ? ProviderFees::collectionFee($schedule, $amount) : null);
        $settlement = $schedule ? ProviderFees::settlementFee($schedule, $amount) : null;

        // No fee data for this provider — the net is simply the gross.
        if ($collection === null && $settlement === null) {
            $transaction->net_amount = round($amount, 4);
            $transaction->fee_estimated = false;

            return;
        }

        $transaction->collection_fee = $collection;
        $transaction->settlement_fee = $settlement;
        $transaction->net_amount = round($amount - (float) $collection - (float) $settlement, 4);
        // The collection fee is estimated unless the provider reported the actual.
        $transaction->fee_estimated = $actual === null;
    }
}
