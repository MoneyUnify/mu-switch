<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\LencoController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Create a user with a Lenco provider and a pending transaction ready to verify.
 *
 * @return array{0: User, 1: Transaction}
 */
function pendingTransaction(?string $callbackUrl = null): array
{
    $user = User::factory()->create(['api_token' => 'verify-token']);
    $customer = Customer::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'class' => LencoController::class,
        'config' => ['api_key' => 'lenco_key'],
        'is_active' => true,
    ]);

    $transaction = Transaction::create([
        'transaction_id' => 'txn-ref-1',
        'payment_provider_id' => $provider->id,
        'provider_transaction_id' => 'LENCO-REF-1',
        'customer_id' => $customer->id,
        'amount' => 50,
        'currency' => 'ZMW',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
        'callback_url' => $callbackUrl,
    ]);

    return [$user, $transaction];
}

function fakeLencoStatus(string $status): void
{
    Http::fake([
        '*/collections/status/*' => Http::response([
            'status' => true,
            'message' => 'Retrieved',
            'data' => ['status' => $status, 'lencoReference' => 'LENCO-REF-1'],
        ], 200),
        'merchant.test/*' => Http::response(['received' => true], 200),
    ]);
}

test('verifying a successful collection updates the status and fires the callback', function () {
    [$user, $transaction] = pendingTransaction('https://merchant.test/webhook');
    fakeLencoStatus('successful');

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success') // envelope mirrors the outcome
        ->assertJsonPath('data.status', 'success');

    $transaction->refresh();
    expect($transaction->status)->toBe(TransactionStatus::SUCCESS)
        ->and($transaction->callback_notified_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'merchant.test')
        && $request['status'] === 'success'
        && $request['transaction_id'] === 'txn-ref-1');
});

test('verifying a failed collection updates the status and fires the callback', function () {
    [$user, $transaction] = pendingTransaction('https://merchant.test/webhook');
    fakeLencoStatus('failed');

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed') // envelope mirrors the outcome
        ->assertJsonPath('message', 'Transaction failed')
        ->assertJsonPath('data.status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'merchant.test') && $request['status'] === 'failed');
});

test('verifying a still-pending collection does not fire the callback', function () {
    [$user, $transaction] = pendingTransaction('https://merchant.test/webhook');
    fakeLencoStatus('pay-offline');

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'pending') // envelope mirrors the outcome
        ->assertJsonPath('data.status', 'pending');

    expect($transaction->refresh()->callback_notified_at)->toBeNull();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'merchant.test'));
});

test('no callback is attempted when the transaction has no callback url', function () {
    [$user, $transaction] = pendingTransaction(null);
    fakeLencoStatus('successful');

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'merchant.test'));
});

test('a settled transaction is only ever notified once', function () {
    [$user, $transaction] = pendingTransaction('https://merchant.test/webhook');
    fakeLencoStatus('successful');

    $verify = fn () => $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk();

    $verify();
    $verify(); // second verification of an already-notified transaction

    Http::assertSentCount(3); // 2 status checks + 1 single callback
});

test('verification is scoped to the authenticated account', function () {
    [, $transaction] = pendingTransaction('https://merchant.test/webhook');
    $intruder = User::factory()->create(['api_token' => 'intruder-token']);
    fakeLencoStatus('successful');

    $this->withToken($intruder->api_token)
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertStatus(404);
});

test('the request endpoint persists a supplied callback url', function () {
    $user = User::factory()->create(['api_token' => 'req-token']);
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'class' => LencoController::class,
        'config' => ['api_key' => 'lenco_key'],
        'is_active' => true,
    ]);

    Http::fake([
        '*/resolve/mobile-money' => Http::response(['status' => true, 'data' => ['accountName' => 'Jane', 'operator' => 'mtn', 'country' => 'zm']], 200),
        '*/collections/mobile-money' => Http::response(['status' => true, 'data' => ['lencoReference' => 'LENCO-NEW']], 200),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', [
            'amount' => 25,
            'account_number' => '0971943638',
            'country' => 'ZM',
            'callback_url' => 'https://merchant.test/webhook',
        ])
        ->assertOk();

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'LENCO-NEW',
        'callback_url' => 'https://merchant.test/webhook',
    ]);
});

test('a successful request reports the initiated transaction as pending', function () {
    $user = User::factory()->create(['api_token' => 'pending-token']);
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'class' => LencoController::class,
        'config' => ['api_key' => 'lenco_key'],
        'is_active' => true,
    ]);

    Http::fake([
        '*/resolve/mobile-money' => Http::response(['status' => true, 'data' => ['accountName' => 'Jane', 'operator' => 'mtn', 'country' => 'zm']], 200),
        '*/collections/mobile-money' => Http::response(['status' => true, 'data' => ['lencoReference' => 'LENCO-P']], 200),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', ['amount' => 10, 'account_number' => '0971943638', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');
});

test('a provider decline at http 200 is surfaced as a 4xx error, not a 200', function () {
    $user = User::factory()->create(['api_token' => 'decline-token']);
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'class' => LencoController::class,
        'config' => ['api_key' => 'lenco_key'],
        'is_active' => true,
    ]);

    // Lenco declines logically: HTTP 200 but status:false.
    Http::fake([
        '*/resolve/mobile-money' => Http::response(['status' => true, 'data' => ['accountName' => 'Jane', 'operator' => 'mtn', 'country' => 'zm']], 200),
        '*/collections/mobile-money' => Http::response(['status' => false, 'message' => 'Insufficient funds'], 200),
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', ['amount' => 10, 'account_number' => '0971943638', 'country' => 'ZM'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Insufficient funds');

    $this->assertDatabaseHas('transactions', ['status' => 'failed']);
});

test('the request endpoint rejects an invalid callback url', function () {
    $user = User::factory()->create(['api_token' => 'req-token-2']);
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'class' => LencoController::class,
        'config' => ['api_key' => 'lenco_key'],
        'is_active' => true,
    ]);

    $this->withToken($user->api_token)
        ->postJson('/api/v1/payment/request', [
            'amount' => 25,
            'account_number' => '0971943638',
            'country' => 'ZM',
            'callback_url' => 'not-a-url',
        ])
        ->assertStatus(422);
});
