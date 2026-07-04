<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\TingController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

function tingProvider(User $user, string $country = 'KE', array $optionCodes = ['KE' => 'SAFKE']): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Ting '.$country,
        'class' => TingController::class,
        'config' => [
            'api_key' => 'ting-api-key',
            'client_id' => 'ting-client',
            'client_secret' => 'ting-secret',
            'service_code' => 'MUONLINE',
            'payment_option_codes' => $optionCodes,
            'supported_countries' => array_keys($optionCodes),
        ],
        'is_active' => true,
    ]);
}

function fakeTingToken(): array
{
    return ['*/v1/oauth/token/request' => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3600, 'token_type' => 'bearer'], 200)];
}

test('the ting driver authenticates and pushes a direct STK charge (no link)', function () {
    $user = User::factory()->create(['api_token' => 'ting-token']);
    tingProvider($user, 'KE');

    Http::fake([
        ...fakeTingToken(),
        '*/v3/checkout-api/checkout-charge' => Http::response([
            'status' => ['status_code' => 200, 'status_description' => 'success'],
            'results' => ['checkout_request_id' => 987654],
        ], 200),
    ]);

    $this->withToken('ting-token')
        ->postJson('/api/v1/payment/request', ['amount' => 600, 'account_number' => '0700123456', 'country' => 'KE'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending')
        // No hosted payment link is returned — the prompt goes straight to the handset.
        ->assertJsonMissingPath('data.payment_url');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => '987654',
        'currency' => 'KES',
        'country' => 'KE',
        'status' => TransactionStatus::PENDING->value,
    ]);

    // The charge is offline STK, routed by the operator payment_option_code,
    // with alpha-3 country, E.164 msisdn, and Bearer + apiKey auth.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/checkout-api/checkout-charge')
        && $request->hasHeader('Authorization', 'Bearer tok-abc')
        && $request->hasHeader('apiKey', 'ting-api-key')
        && $request['is_offline'] === true
        && $request['payment_option_code'] === 'SAFKE'
        && $request['country_code'] === 'KEN'
        && $request['currency_code'] === 'KES'
        && $request['msisdn'] === '254700123456'
        && $request['service_code'] === 'MUONLINE'
        && (float) $request['request_amount'] === 600.0);
});

test('a single ting provider routes each market to its own operator code', function () {
    $user = User::factory()->create(['api_token' => 'ting-multi']);
    // One provider serving both Kenya and Tanzania, each with its own operator code.
    tingProvider($user, 'KE', ['KE' => 'SAFKE', 'TZ' => 'VODACOMTZ']);

    Http::fake([
        ...fakeTingToken(),
        // No checkout_request_id — each transaction keeps its own unique reference.
        '*/v3/checkout-api/checkout-charge' => Http::response(['status' => ['status_code' => 200]], 200),
    ]);

    // A Tanzania request uses the TZ operator code + market.
    $this->withToken('ting-multi')
        ->postJson('/api/v1/payment/request', ['amount' => 5000, 'account_number' => '0712345678', 'country' => 'TZ'])
        ->assertOk();

    $this->assertDatabaseHas('transactions', ['currency' => 'TZS', 'country' => 'TZ']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/checkout-api/checkout-charge')
        && $request['country_code'] === 'TZA'
        && $request['currency_code'] === 'TZS'
        && $request['msisdn'] === '255712345678'
        && $request['payment_option_code'] === 'VODACOMTZ');

    // A Kenya request through the same provider uses the KE operator code.
    $this->withToken('ting-multi')
        ->postJson('/api/v1/payment/request', ['amount' => 600, 'account_number' => '0700123456', 'country' => 'KE'])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/checkout-api/checkout-charge')
        && $request['country_code'] === 'KEN'
        && $request['payment_option_code'] === 'SAFKE');
});

test('the ting driver errors when no operator code is configured for the country', function () {
    $user = User::factory()->create(['api_token' => 'ting-nocode']);
    // Provider serves KE (with a code) and UG (ticked but without a code).
    tingProvider($user, 'KE', ['KE' => 'SAFKE', 'UG' => '']);

    Http::fake([...fakeTingToken()]);

    $this->withToken('ting-nocode')
        ->postJson('/api/v1/payment/request', ['amount' => 100, 'account_number' => '0700123456', 'country' => 'UG'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');
});

test('the ting driver surfaces a rejected checkout as an error', function () {
    $user = User::factory()->create(['api_token' => 'ting-token-2']);
    tingProvider($user, 'KE');

    Http::fake([
        ...fakeTingToken(),
        '*/v3/checkout-api/checkout-charge' => Http::response([
            'status' => ['status_code' => 1013, 'status_description' => 'Invalid service code'],
        ], 400),
    ]);

    $this->withToken('ting-token-2')
        ->postJson('/api/v1/payment/request', ['amount' => 600, 'account_number' => '0700123456', 'country' => 'KE'])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Invalid service code');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});

function pendingTingTransaction(User $user): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-ting@example.com']);

    return Transaction::create([
        'transaction_id' => 'MU20260701ABCD1234',
        'payment_provider_id' => tingProvider($user, 'KE')->id,
        'provider_transaction_id' => '987654',
        'customer_id' => $customer->id,
        'amount' => 600,
        'currency' => 'KES',
        'country' => 'KE',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
    ]);
}

test('the ting driver maps a fully-paid query to success on verification', function () {
    $user = User::factory()->create(['api_token' => 'ting-verify']);
    $transaction = pendingTingTransaction($user);

    Http::fake([
        ...fakeTingToken(),
        '*/v3/checkout-api/query/*' => Http::response([
            'results' => ['request_status_code' => 178, 'request_status_description' => 'Payment received', 'amount_paid' => 600],
        ], 200),
    ]);

    $this->withToken('ting-verify')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', '178');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);

    // Query is by service_code + our merchant_transaction_id (the transaction_id).
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v3/checkout-api/query/MUONLINE/MU20260701ABCD1234')
        && $request->hasHeader('Authorization', 'Bearer tok-abc'));
});

test('the ting driver maps a failed query to failed on verification', function () {
    $user = User::factory()->create(['api_token' => 'ting-verify-2']);
    $transaction = pendingTingTransaction($user);

    Http::fake([
        ...fakeTingToken(),
        '*/v3/checkout-api/query/*' => Http::response([
            'results' => ['request_status_code' => 99, 'request_status_description' => 'Payment failed'],
        ], 200),
    ]);

    $this->withToken('ting-verify-2')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});

test('the ting driver keeps an awaiting-payment query pending', function () {
    $user = User::factory()->create(['api_token' => 'ting-verify-3']);
    $transaction = pendingTingTransaction($user);

    Http::fake([
        ...fakeTingToken(),
        '*/v3/checkout-api/query/*' => Http::response([
            'results' => ['request_status_code' => 160, 'request_status_description' => 'Awaiting payment'],
        ], 200),
    ]);

    $this->withToken('ting-verify-3')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'pending');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING);
});

test('ting supports 25 markets', function () {
    expect(TingController::SUPPORTED_COUNTRIES)->toHaveCount(25);
});
