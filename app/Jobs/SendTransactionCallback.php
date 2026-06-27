<?php

namespace App\Jobs;

use App\Models\Transaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class SendTransactionCallback implements ShouldQueue
{
    use Queueable;

    /**
     * Retry the callback a few times with a growing delay before giving up.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * @var array<int, int>
     */
    public $backoff = [10, 30, 60];

    /**
     * The transaction is serialised by id and refetched when the job runs,
     * so the callback always reflects its latest persisted state.
     */
    public function __construct(public Transaction $transaction) {}

    /**
     * POST the final transaction result to the merchant's callback URL.
     *
     * Only settled transactions (succeeded or failed) with a callback URL that
     * have not already been notified are delivered, so the merchant receives
     * exactly one callback per transaction.
     */
    public function handle(): void
    {
        if (! $this->transaction->callback_url || $this->transaction->callback_notified_at || ! $this->transaction->isFinal()) {
            return;
        }

        Http::asJson()
            ->acceptJson()
            ->timeout(15)
            ->post($this->transaction->callback_url, [
                'transaction_id' => $this->transaction->transaction_id,
                'reference' => $this->transaction->provider_transaction_id,
                'status' => $this->transaction->status->value,
                'amount' => (float) $this->transaction->amount,
                'currency' => $this->transaction->currency,
                'provider' => $this->transaction->paymentProvider?->name,
            ])
            ->throw();

        // Record successful delivery so the same transaction is never notified twice.
        $this->transaction->forceFill(['callback_notified_at' => now()])->save();
    }
}
