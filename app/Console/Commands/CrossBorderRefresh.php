<?php

namespace App\Console\Commands;

use App\Enums\CrossBorderStatus;
use App\Models\CrossBorderTransfer;
use App\Support\CrossBorder\CrossBorderTransferService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Moves in-flight cross-border transfers forward: confirms payouts, starts or
 * retries refunds, and picks up PayPal payments approved without a return.
 */
class CrossBorderRefresh extends Command
{
    protected $signature = 'cross-border:refresh';

    protected $description = 'Re-check cross-border transfers that are still in progress';

    public function handle(CrossBorderTransferService $transfers): int
    {
        $checked = 0;

        CrossBorderTransfer::query()
            ->where(fn ($query) => $query
                ->whereIn('status', [CrossBorderStatus::FUNDED->value, CrossBorderStatus::PAYING_OUT->value, CrossBorderStatus::PAYOUT_FAILED->value, CrossBorderStatus::REFUNDING->value])
                // An unpaid PayPal order expires, so only recent ones are worth checking.
                ->orWhere(fn ($recent) => $recent->where('status', CrossBorderStatus::AWAITING_PAYMENT->value)->where('created_at', '>=', now()->subDay())))
            ->orderBy('id')
            ->each(function (CrossBorderTransfer $transfer) use ($transfers, &$checked): void {
                try {
                    $transfers->refresh($transfer);
                    $checked++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        $this->info("Checked {$checked} transfer(s).");

        return self::SUCCESS;
    }
}
