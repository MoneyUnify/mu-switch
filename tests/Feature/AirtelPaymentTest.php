<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\AirtelController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

function airtelProvider(User $user): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Airtel Money',
        'class' => AirtelController::class,
        'config' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
}

function fakeAirtelToken(): array
{
    return ['*/auth/oauth2/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 3600, 'token_type' => 'bearer'], 200)];
}

test('the airtel driver authenticates and initiates a collection', function () {
    $user = User::factory()->create(['api_token' => 'airtel-token']);
    airtelProvider($user);

    Http::fake([
        ...fakeAirtelToken(),
        '*/merchant/v1/payments/' => Http::response([
            'data' => ['transaction' => ['id' => 'AIRTEL-1', 'status' => 'TIP']],
            'status' => ['code' => '200', 'success' => true, 'message' => 'Transaction initiated'],
        ], 200),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', ['amount' => 30, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'AIRTEL-1',
        'currency' => 'ZMW',
        'status' => TransactionStatus::PENDING->value,
    ]);

    // The OAuth token was obtained and the collection sent with the bearer token.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/auth/oauth2/token'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/merchant/v1/payments')
        && $request->hasHeader('Authorization', 'Bearer tok-123')
        && $request['subscriber']['msisdn'] === '977123456');
});

test('the airtel driver uses the correct currency and country headers per market', function () {
    $user = User::factory()->create(['api_token' => 'airtel-ke']);
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Airtel Kenya',
        'class' => AirtelController::class,
        'config' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'supported_countries' => ['KE']],
        'is_active' => true,
    ]);

    Http::fake([
        ...fakeAirtelToken(),
        '*/merchant/v1/payments/' => Http::response([
            'data' => ['transaction' => ['id' => 'AIRTEL-KE', 'status' => 'TIP']],
            'status' => ['success' => true],
        ], 200),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', ['amount' => 100, 'account_number' => '0712345678', 'country' => 'KE'])
        ->assertOk();

    $this->assertDatabaseHas('transactions', ['currency' => 'KES', 'country' => 'KE']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/merchant/v1/payments')
        && $request->hasHeader('X-Country', 'KE')
        && $request->hasHeader('X-Currency', 'KES')
        && $request['subscriber']['msisdn'] === '712345678');
});

test('the airtel driver surfaces a declined collection as an error', function () {
    $user = User::factory()->create(['api_token' => 'airtel-token-2']);
    airtelProvider($user);

    Http::fake([
        ...fakeAirtelToken(),
        '*/merchant/v1/payments/' => Http::response([
            'status' => ['code' => '400', 'success' => false, 'message' => 'Invalid subscriber'],
        ], 400),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', ['amount' => 30, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Invalid subscriber');
});

function pendingAirtelTransaction(User $user): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-airtel@example.com']);

    return Transaction::create([
        'transaction_id' => 'air-ref-1',
        'payment_provider_id' => airtelProvider($user)->id,
        'provider_transaction_id' => 'air-ref-1',
        'customer_id' => $customer->id,
        'amount' => 30,
        'currency' => 'ZMW',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
    ]);
}

test('the airtel driver maps a TS status to success on verification', function () {
    $user = User::factory()->create(['api_token' => 'airtel-verify']);
    $transaction = pendingAirtelTransaction($user);

    Http::fake([
        ...fakeAirtelToken(),
        '*/standard/v1/payments/*' => Http::response([
            'data' => ['transaction' => ['id' => 'air-ref-1', 'status' => 'TS', 'airtel_money_id' => 'AM-9']],
            'status' => ['code' => '200', 'success' => true],
        ], 200),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', 'TS');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('the airtel driver maps a TF status to failed on verification', function () {
    $user = User::factory()->create(['api_token' => 'airtel-verify-2']);
    $transaction = pendingAirtelTransaction($user);

    Http::fake([
        ...fakeAirtelToken(),
        '*/standard/v1/payments/*' => Http::response([
            'data' => ['transaction' => ['id' => 'air-ref-1', 'status' => 'TF']],
            'status' => ['code' => '200', 'success' => true],
        ], 200),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});
