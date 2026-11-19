<?php

namespace App\Console\Commands;

use App\Support\Treasury\FloatMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('treasury:check-floats')]
#[Description('Re-check every float rule against its ledger balance and open or resolve alerts.')]
class TreasuryCheckFloats extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(FloatMonitor $monitor): int
    {
        $low = $monitor->checkAll();

        $this->components->info($low === 0 ? 'All floats are above their minimum.' : "{$low} float(s) below their minimum.");

        return self::SUCCESS;
    }
}
