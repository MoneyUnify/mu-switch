<?php

namespace App\Console\Commands;

use App\Models\PaymentProvider;
use App\Support\Currency;
use App\Support\Ledger\LedgerRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ledger:opening-balance {provider : The payment provider id} {currency : ISO 4217 currency code, e.g. ZMW} {amount : The balance already held at the provider} {--note= : Why the balance is being recorded}')]
#[Description('Record money already held at a provider, so the ledger matches the real account.')]
class LedgerOpeningBalance extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(LedgerRecorder $recorder): int
    {
        $provider = PaymentProvider::find($this->argument('provider'));
        $currency = strtoupper((string) $this->argument('currency'));
        $amount = (string) $this->argument('amount');

        if (! $provider) {
            $this->components->error('Provider not found.');

            return self::FAILURE;
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency) || ! is_numeric($amount) || (float) $amount <= 0) {
            $this->components->error('Give a 3-letter currency and a positive amount.');

            return self::FAILURE;
        }

        $recorder->recordOpeningBalance($provider, $currency, $amount, $this->option('note'));

        $recorded = Currency::fromMinorUnits(Currency::toMinorUnits($amount, $currency), $currency);
        $this->components->info("Opening balance of {$recorded} {$currency} recorded for {$provider->name}.");

        return self::SUCCESS;
    }
}
