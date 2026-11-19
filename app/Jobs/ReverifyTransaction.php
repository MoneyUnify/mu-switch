<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Support\TransactionVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Re-verifies a transaction through its driver in the background (e.g. syncing
 * latest PayPal order status or refunds) without blocking HTTP responses.
 */
class ReverifyTransaction implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Transaction $transaction) {}

    public function handle(TransactionVerifier $verifier): void
    {
        try {
            $verifier->verify($this->transaction);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
