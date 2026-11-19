<?php

namespace App\Jobs;

use App\Models\CrossBorderTransfer;
use App\Support\CrossBorder\CrossBorderTransferService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refunds the sender's PayPal payment when a transfer couldn't be delivered.
 * Safe to run twice: the same refund record (and PayPal request id) is reused.
 */
class RefundCrossBorderTransfer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $transferId) {}

    public function uniqueId(): string
    {
        return (string) $this->transferId;
    }

    public function handle(CrossBorderTransferService $transfers): void
    {
        if ($transfer = CrossBorderTransfer::find($this->transferId)) {
            $transfers->refund($transfer);
        }
    }
}
