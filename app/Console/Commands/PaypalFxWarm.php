<?php

namespace App\Console\Commands;

use App\Http\Controllers\Providers\PaypalController;
use App\Models\PaymentProvider;
use App\Support\Fx\Contracts\FxRateProviderInterface;
use App\Support\Paypal\PaypalClient;
use App\Support\Paypal\PaypalFxRates;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('paypal-fx:warm')]
#[Description('Fetch PayPal\'s exchange rates ahead of time for each PayPal account with PayPal FX, so quotes never wait on PayPal.')]
class PaypalFxWarm extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PaypalFxRates $paypalFx, FxRateProviderInterface $rates): int
    {
        $currencies = $rates->supportedCurrencies();
        $warmed = 0;

        PaymentProvider::query()
            ->where('class', PaypalController::class)
            ->where('is_active', true)
            ->get()
            ->filter(fn (PaymentProvider $provider): bool => PaypalFxRates::wanted($provider) && PaypalClient::fromConfig((array) $provider->config) !== null)
            ->each(function (PaymentProvider $provider) use ($paypalFx, $currencies, &$warmed): void {
                $settlement = strtoupper((string) ($provider->config['fx_settlement_currency'] ?? '') ?: 'USD');

                foreach (array_unique(['USD', $settlement]) as $base) {
                    $warmed += count($paypalFx->quotes($provider, $base, $currencies));
                }
            });

        $this->components->info("{$warmed} PayPal rate(s) ready.");

        return self::SUCCESS;
    }
}
