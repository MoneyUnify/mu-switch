<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\FlutterwaveController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function flutterwaveProvider(User $user, array $countries = ['KE']): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Flutterwave '.implode('', $countries),
        'class' => FlutterwaveController::class,
        'config' => ['secret_key' => 'FLWSECK-test', 'supported_countries' => $countries],
        'is_active' => true,
    ]);
}

function flwChargeOk(string $status = 'pending'): array
{
    return ['*/v3/charges*' => Http::response([
        'status' => 'success',
        'message' => 'Charge initiated',
        'data' => ['id' => 288200108, 'tx_ref' => 'ref', 'flw_ref' => 'FLW-MOCK', 'status' => $status, 'currency' => 'KES', 'amount' => 1500],
    ], 200)];
}

test('the flutterwave driver initiates an M-Pesa charge in Kenya', function () {
    $user = User::factory()->create(['api_token' => 'flw-ke']);
    flutterwaveProvider($user, ['KE']);

    Http::fake(flwChargeOk());

    $this->withToken('flw-ke')
        ->postJson('/api/v1/payment/request', ['amount' => 1500, 'account_number' => '0712345678', 'country' => 'KE'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => '288200108',
        'currency' => 'KES',
        'country' => 'KE',
        'status' => TransactionStatus::PENDING->value,
    ]);

    // Charged via the mpesa type with a Bearer secret key and E.164 msisdn.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/charges?type=mpesa')
        && $request->hasHeader('Authorization', 'Bearer FLWSECK-test')
        && $request['phone_number'] === '254712345678'
        && $request['currency'] === 'KES'
        && (float) $request['amount'] === 1500.0);
});

test('the flutterwave driver derives the network for Ghana from the phone prefix', function () {
    $user = User::factory()->create(['api_token' => 'flw-gh']);
    flutterwaveProvider($user, ['GH']);

    Http::fake(flwChargeOk());

    // 024 is an MTN Ghana prefix.
    $this->withToken('flw-gh')
        ->postJson('/api/v1/payment/request', ['amount' => 50, 'account_number' => '0241234567', 'country' => 'GH'])
        ->assertOk();

    $this->assertDatabaseHas('transactions', ['currency' => 'GHS', 'country' => 'GH']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/charges?type=mobile_money_ghana')
        && $request['network'] === 'MTN'
        && $request['phone_number'] === '233241234567');
});

test('the flutterwave driver sends the country for francophone markets', function () {
    $user = User::factory()->create(['api_token' => 'flw-ci']);
    flutterwaveProvider($user, ['CI']);

    Http::fake(flwChargeOk());

    $this->withToken('flw-ci')
        ->postJson('/api/v1/payment/request', ['amount' => 1000, 'account_number' => '0709929220', 'country' => 'CI'])
        ->assertOk();

    $this->assertDatabaseHas('transactions', ['currency' => 'XOF', 'country' => 'CI']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/charges?type=mobile_money_franco')
        && $request['country'] === 'CI'
        && $request['currency'] === 'XOF');
});

test('the flutterwave driver surfaces a declined charge as an error', function () {
    $user = User::factory()->create(['api_token' => 'flw-fail']);
    flutterwaveProvider($user, ['KE']);

    Http::fake([
        '*/v3/charges*' => Http::response(['status' => 'error', 'message' => 'Invalid phone number'], 400),
    ]);

    $this->withToken('flw-fail')
        ->postJson('/api/v1/payment/request', ['amount' => 1500, 'account_number' => '0712345678', 'country' => 'KE'])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Invalid phone number');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});

function pendingFlutterwaveTransaction(User $user): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-flw@example.com']);

    return Transaction::create([
        'transaction_id' => 'MU-flw-ref-1',
        'payment_provider_id' => flutterwaveProvider($user, ['KE'])->id,
        'provider_transaction_id' => '288200108',
        'customer_id' => $customer->id,
        'amount' => 1500,
        'currency' => 'KES',
        'country' => 'KE',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
    ]);
}

test('the flutterwave driver maps a successful verification to success', function () {
    $user = User::factory()->create(['api_token' => 'flw-verify']);
    $transaction = pendingFlutterwaveTransaction($user);

    Http::fake([
        '*/v3/transactions/288200108/verify' => Http::response([
            'status' => 'success',
            'data' => ['id' => 288200108, 'status' => 'successful', 'amount' => 1500, 'currency' => 'KES'],
        ], 200),
    ]);

    $this->withToken('flw-verify')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', 'successful');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('the flutterwave driver maps a failed verification to failed', function () {
    $user = User::factory()->create(['api_token' => 'flw-verify-2']);
    $transaction = pendingFlutterwaveTransaction($user);

    Http::fake([
        '*/v3/transactions/288200108/verify' => Http::response([
            'status' => 'success',
            'data' => ['id' => 288200108, 'status' => 'failed'],
        ], 200),
    ]);

    $this->withToken('flw-verify-2')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});
