<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\MtnController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

function mtnProvider(User $user): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'MTN MoMo',
        'class' => MtnController::class,
        'config' => [
            'subscription_key' => 'sub-key',
            'api_user' => 'api-user-uuid',
            'api_key' => 'api-key',
            'supported_countries' => ['ZM'],
        ],
        'is_active' => true,
    ]);
}

function fakeMtnToken(): array
{
    return ['*/collection/token/' => Http::response(['access_token' => 'mtn-tok', 'token_type' => 'access_token', 'expires_in' => 3600], 200)];
}

test('the mtn driver authenticates and initiates a request-to-pay', function () {
    $user = User::factory()->create(['api_token' => 'mtn-token']);
    mtnProvider($user);

    Http::fake([
        ...fakeMtnToken(),
        // MTN acknowledges request-to-pay with HTTP 202 (no body).
        '*/collection/v1_0/requesttopay' => Http::response('', 202),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', ['amount' => 40, 'account_number' => '0966123456', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('transactions', [
        'currency' => 'ZMW',
        'status' => TransactionStatus::PENDING->value,
    ]);

    // The production token request carries the subscription key and target environment.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/collection/token/')
        && $request->hasHeader('Ocp-Apim-Subscription-Key', 'sub-key')
        && $request->hasHeader('X-Target-Environment', 'mtnzambia'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/requesttopay')
        && $request->hasHeader('Authorization', 'Bearer mtn-tok')
        && $request->hasHeader('Ocp-Apim-Subscription-Key', 'sub-key')
        // The target environment is derived from the country (ZM → mtnzambia), not configured.
        && $request->hasHeader('X-Target-Environment', 'mtnzambia')
        && $request->hasHeader('X-Reference-Id')
        && $request['payer']['partyId'] === '260966123456');
});

test('the mtn driver uses the correct currency, environment and msisdn per market', function () {
    $user = User::factory()->create(['api_token' => 'mtn-gh']);
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'MTN Ghana',
        'class' => MtnController::class,
        'config' => ['subscription_key' => 'sub-key', 'api_user' => 'u', 'api_key' => 'k', 'supported_countries' => ['GH']],
        'is_active' => true,
    ]);

    Http::fake([
        ...fakeMtnToken(),
        '*/collection/v1_0/requesttopay' => Http::response('', 202),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', ['amount' => 50, 'account_number' => '0244123456', 'country' => 'GH'])
        ->assertOk();

    $this->assertDatabaseHas('transactions', ['currency' => 'GHS', 'country' => 'GH']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/requesttopay')
        && $request->hasHeader('X-Target-Environment', 'mtnghana')
        && $request['currency'] === 'GHS'
        && $request['payer']['partyId'] === '233244123456');
});

test('the mtn driver surfaces a rejected request-to-pay as an error', function () {
    $user = User::factory()->create(['api_token' => 'mtn-token-2']);
    mtnProvider($user);

    Http::fake([
        ...fakeMtnToken(),
        '*/collection/v1_0/requesttopay' => Http::response(['message' => 'Payer not found'], 400),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', ['amount' => 40, 'account_number' => '0966123456', 'country' => 'ZM'])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Payer not found');
});

function pendingMtnTransaction(User $user): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-mtn@example.com']);

    return Transaction::create([
        'transaction_id' => 'mtn-ref-1',
        'payment_provider_id' => mtnProvider($user)->id,
        'provider_transaction_id' => 'mtn-ref-1',
        'customer_id' => $customer->id,
        'amount' => 40,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
    ]);
}

test('the mtn driver maps a SUCCESSFUL status to success on verification', function () {
    $user = User::factory()->create(['api_token' => 'mtn-verify']);
    $transaction = pendingMtnTransaction($user);

    Http::fake([
        ...fakeMtnToken(),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => '40', 'currency' => 'ZMW'], 200),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', 'SUCCESSFUL');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('the mtn driver maps a FAILED status to failed on verification', function () {
    $user = User::factory()->create(['api_token' => 'mtn-verify-2']);
    $transaction = pendingMtnTransaction($user);

    Http::fake([
        ...fakeMtnToken(),
        '*/collection/v1_0/requesttopay/*' => Http::response(['status' => 'FAILED', 'reason' => 'PAYER_LIMIT_REACHED'], 200),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});
