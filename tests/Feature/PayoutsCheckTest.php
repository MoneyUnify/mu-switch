<?php

use App\Http\Controllers\Providers\LencoController;
use App\Http\Controllers\Providers\PawapayController;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    $this->user = User::factory()->create();
});

function pawapaySandbox(User $user, string $environment = 'sandbox'): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'pawaPay '.$environment,
        'class' => PawapayController::class,
        'config' => ['api_token' => 'pawapay-token', 'environment' => $environment, 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
}

test('a sandbox payout is sent to pawapay\'s completing test number and followed to the end', function () {
    $provider = pawapaySandbox($this->user);
    Http::fake([
        'api.sandbox.pawapay.io/v2/predict-provider' => Http::response(['country' => 'ZMB', 'provider' => 'MTN_MOMO_ZMB', 'phoneNumber' => '260763456789']),
        'api.sandbox.pawapay.io/v2/payouts' => Http::response(['status' => 'ACCEPTED']),
        'api.sandbox.pawapay.io/v2/payouts/*' => Http::sequence()
            ->push(['status' => 'FOUND', 'data' => ['status' => 'PROCESSING']])
            ->push(['status' => 'FOUND', 'data' => ['status' => 'COMPLETED']]),
    ]);

    $this->artisan('payouts:check', ['provider' => $provider->id])
        ->expectsOutputToContain('Sending ZMW 1 to 260763456789 through pawaPay sandbox')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.sandbox.pawapay.io/v2/payouts'
        && $request['recipient']['accountDetails']['phoneNumber'] === '260763456789'
        && $request['currency'] === 'ZMW');
    expect(Transaction::sole()->status->value)->toBe('success');
});

test('a refused sandbox payout fails the check', function () {
    $provider = pawapaySandbox($this->user);
    Http::fake([
        '*/v2/predict-provider' => Http::response(['country' => 'ZMB', 'provider' => 'MTN_MOMO_ZMB', 'phoneNumber' => '260763456789']),
        '*/v2/payouts' => Http::response(['status' => 'REJECTED', 'failureReason' => ['failureCode' => 'INSUFFICIENT_BALANCE', 'failureMessage' => 'Insufficient balance']]),
    ]);

    $this->artisan('payouts:check', ['provider' => $provider->id])
        ->expectsOutputToContain('Insufficient balance')
        ->assertFailed();
});

test('the check never runs against a live account or a provider without a sandbox', function () {
    Http::fake();
    $live = pawapaySandbox($this->user, 'live');
    $lenco = PaymentProvider::create(['user_id' => $this->user->id, 'name' => 'Lenco', 'class' => LencoController::class, 'config' => ['api_key' => 'k'], 'is_active' => true]);

    $this->artisan('payouts:check', ['provider' => $live->id])->assertFailed();
    $this->artisan('payouts:check', ['provider' => $lenco->id])->assertFailed();

    Http::assertNothingSent();
    expect(Transaction::count())->toBe(0);
});
