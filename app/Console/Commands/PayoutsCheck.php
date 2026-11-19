<?php

namespace App\Console\Commands;

use App\Contracts\PayoutProviderInterface;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\PaymentProvider;
use App\Support\DriverRegistry;
use App\Support\Market;
use App\Support\Payouts\PayoutDispatcher;
use App\Support\TransactionVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;

#[Signature('payouts:check {provider : The payout provider id} {--number= : The recipient number (default: the provider sandbox\'s "completed" test number)} {--amount=1 : How much to send} {--country=ZM : The recipient\'s country}')]
#[Description('Send a small payout through a sandbox payout provider and follow it to its final status. Sandboxes only: it never moves real money.')]
class PayoutsCheck extends Command
{
    /**
     * pawaPay sandbox numbers that complete, per country (docs.pawapay.io/v2/docs/test_numbers).
     *
     * @var array<string, string>
     */
    private const PAWAPAY_COMPLETED = ['ZM' => '260763456789'];

    /** How many times to re-check a payout that's still processing. */
    private const CHECKS = 6;

    public function handle(PayoutDispatcher $payouts, TransactionVerifier $verifier): int
    {
        $provider = PaymentProvider::find($this->argument('provider'));

        if (! $provider || ! is_subclass_of($provider->class, PayoutProviderInterface::class)) {
            $this->components->error('That isn\'t a payout provider.');

            return self::FAILURE;
        }

        if (! $this->isSandbox($provider)) {
            $this->components->error("{$provider->name} isn't a sandbox, so this check would move real money. It only runs against sandboxes.");

            return self::FAILURE;
        }

        $country = strtoupper((string) $this->option('country'));
        $number = (string) ($this->option('number') ?: (self::PAWAPAY_COMPLETED[$country] ?? ''));
        $currency = (string) Market::currency($country);

        if ($number === '' || $currency === '') {
            $this->components->error('Give a recipient --number for this country.');

            return self::FAILURE;
        }

        $amount = (string) $this->option('amount');
        $this->components->info("Sending {$currency} {$amount} to {$number} through {$provider->name}…");

        $result = $payouts->send([$provider], ['account_number' => $number, 'narration' => 'MoneyUnify payout check'], $amount, $currency, $country);
        $payout = $result->transaction;

        // Accepted, or no definite answer yet: either way, follow it. Only a refusal ends here.
        if ($result->refused() || ! $payout) {
            $data = $result->response->getData(true);
            $this->components->error("{$provider->name} refused the payout: ".($data['message'] ?? 'no reason given'));

            return self::FAILURE;
        }

        for ($check = 1; $check <= self::CHECKS && ! $payout->refresh()->isFinal(); $check++) {
            Sleep::for(3)->seconds();
            $verifier->verify($payout);
        }

        $status = $payout->refresh()->status->value;
        $this->components->twoColumnDetail('Payout', $payout->transaction_id);
        $this->components->twoColumnDetail("{$provider->name} reference", (string) $payout->provider_transaction_id);
        $this->components->twoColumnDetail('Status', $status);

        return $status === 'success' ? self::SUCCESS : self::FAILURE;
    }

    private function isSandbox(PaymentProvider $provider): bool
    {
        return DriverRegistry::isSandboxOnly($provider->class)
            || $provider->class === SandboxPayoutController::class
            || ($provider->config['environment'] ?? null) === 'sandbox';
    }
}
