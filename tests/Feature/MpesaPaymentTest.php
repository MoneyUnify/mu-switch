<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\MpesaController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

function mpesaProvider(User $user): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'M-Pesa Kenya',
        'class' => MpesaController::class,
        'config' => [
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
            'shortcode' => '174379',
            'passkey' => 'pk',
            'supported_countries' => ['KE'],
        ],
        'is_active' => true,
    ]);
}

function fakeDarajaToken(): array
{
    return ['*/oauth/v1/generate*' => Http::response(['access_token' => 'tok-1', 'expires_in' => 3600], 200)];
}

test('the mpesa driver initiates an STK push', function () {
    $user = User::factory()->create(['api_token' => 'mpesa-token']);
    mpesaProvider($user);

    Http::fake([
        ...fakeDarajaToken(),
        '*/mpesa/stkpush/v1/processrequest' => Http::response([
            'MerchantRequestID' => 'MR-1',
            'CheckoutRequestID' => 'ws_CO_123',
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success. Request accepted for processing',
            'CustomerMessage' => 'Success. Request accepted for processing',
        ], 200),
    ]);

    $this->withToken('mpesa-token')
        ->postJson('/api/v1/payment/request', ['amount' => 100, 'account_number' => '0712345678', 'country' => 'KE'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'ws_CO_123',
        'currency' => 'KES',
        'country' => 'KE',
        'status' => TransactionStatus::PENDING->value,
    ]);

    // STK Push is sent to the right msisdn, shortcode and a base64 password.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/mpesa/stkpush/v1/processrequest')
        && $request->hasHeader('Authorization', 'Bearer tok-1')
        && $request['PhoneNumber'] === '254712345678'
        && $request['BusinessShortCode'] === '174379'
        && $request['Amount'] === 100
        && base64_decode((string) $request['Password']) === '174379'.'pk'.$request['Timestamp']);
});

test('the mpesa driver surfaces a rejected STK push as an error', function () {
    $user = User::factory()->create(['api_token' => 'mpesa-token-2']);
    mpesaProvider($user);

    Http::fake([
        ...fakeDarajaToken(),
        '*/mpesa/stkpush/v1/processrequest' => Http::response([
            'requestId' => 'r-1',
            'errorCode' => '400.002.02',
            'errorMessage' => 'Bad Request - Invalid Amount',
        ], 400),
    ]);

    $this->withToken('mpesa-token-2')
        ->postJson('/api/v1/payment/request', ['amount' => 100, 'account_number' => '0712345678', 'country' => 'KE'])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Bad Request - Invalid Amount');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});

function pendingMpesaTransaction(User $user): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-mpesa@example.com']);

    return Transaction::create([
        'transaction_id' => 'mpesa-ref-1',
        'payment_provider_id' => mpesaProvider($user)->id,
        'provider_transaction_id' => 'ws_CO_123',
        'customer_id' => $customer->id,
        'amount' => 100,
        'currency' => 'KES',
        'country' => 'KE',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
    ]);
}

test('the mpesa driver maps a successful STK query to success', function () {
    $user = User::factory()->create(['api_token' => 'mpesa-verify']);
    $transaction = pendingMpesaTransaction($user);

    Http::fake([
        ...fakeDarajaToken(),
        '*/mpesa/stkpushquery/v1/query' => Http::response([
            'ResponseCode' => '0',
            'ResultCode' => '0',
            'ResultDesc' => 'The service request is processed successfully.',
        ], 200),
    ]);

    $this->withToken('mpesa-verify')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', '0');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('the mpesa driver maps a cancelled STK query to failed', function () {
    $user = User::factory()->create(['api_token' => 'mpesa-verify-2']);
    $transaction = pendingMpesaTransaction($user);

    Http::fake([
        ...fakeDarajaToken(),
        '*/mpesa/stkpushquery/v1/query' => Http::response([
            'ResponseCode' => '0',
            'ResultCode' => '1032',
            'ResultDesc' => 'Request cancelled by user',
        ], 200),
    ]);

    $this->withToken('mpesa-verify-2')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});

test('the mpesa driver treats an in-progress STK query as pending', function () {
    $user = User::factory()->create(['api_token' => 'mpesa-verify-3']);
    $transaction = pendingMpesaTransaction($user);

    Http::fake([
        ...fakeDarajaToken(),
        '*/mpesa/stkpushquery/v1/query' => Http::response([
            'requestId' => 'r-1',
            'errorCode' => '500.001.1001',
            'errorMessage' => 'The transaction is being processed',
        ], 500),
    ]);

    $this->withToken('mpesa-verify-3')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'pending');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING);
});
