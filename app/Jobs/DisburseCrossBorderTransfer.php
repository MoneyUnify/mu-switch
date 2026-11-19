<?php

namespace App\Jobs;

use App\Models\CrossBorderTransfer;
use App\Support\CrossBorder\CrossBorderTransferService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pays a funded cross-border transfer out to the recipient's mobile wallet.
 * Safe to run twice: only a transfer still marked funded is paid out.
 */
class DisburseCrossBorderTransfer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A payout must never be retried blindly; the transfer's state decides.
     */
    public int $tries = 1;

    public function __construct(public int $transferId) {}

    public function uniqueId(): string
    {
        return (string) $this->transferId;
    }

    public function handle(CrossBorderTransferService $transfers): void
    {
        if ($transfer = CrossBorderTransfer::find($this->transferId)) {
            $transfers->disburse($transfer);
        }
    }
}
