<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:seed')]
#[Description('Add a demo account with a week of PayPal-to-mobile-money activity (never in production).')]
class DemoSeed extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // The demo pays out through Simulated Payouts, which production never offers.
        if ($this->laravel->isProduction()) {
            $this->components->error('Demo data is never added in production.');

            return self::FAILURE;
        }

        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $this->components->twoColumnDetail('Demo login', DemoSeeder::EMAIL.' / '.DemoSeeder::PASSWORD);

        if (blank(config('services.paypal.sandbox_client_id'))) {
            $this->components->info('Add your PayPal sandbox app on the Providers page (or set PAYPAL_SANDBOX_CLIENT_ID and PAYPAL_SANDBOX_CLIENT_SECRET before seeding) to make new payments.');
        }

        return self::SUCCESS;
    }
}
