<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Support\Ledger\LedgerRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ledger:rebuild')]
#[Description('Sync ledger entries with every transaction (safe to run repeatedly).')]
class LedgerRebuild extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LedgerRecorder $recorder): int
    {
        $synced = 0;

        Transaction::query()->with('paymentProvider')->chunkById(500, function ($transactions) use ($recorder, &$synced): void {
            foreach ($transactions as $transaction) {
                $recorder->sync($transaction);
                $synced++;
            }
        });

        $this->components->info("Ledger synced with {$synced} transactions.");

        return self::SUCCESS;
    }
}
