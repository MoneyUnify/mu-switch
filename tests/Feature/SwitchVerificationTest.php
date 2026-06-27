<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\LencoController;
use App\Jobs\SendTransactionCallback;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Create a Lenco provider + a pending transaction for the given user.
 *
 * @return array{0: PaymentProvider, 1: Transaction}
 */
function lencoTransaction(User $user, array $overrides = []): array
{
    $customer = Customer::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'config' => ['api_key' => 'lenco_key'],
        'class' => LencoController::class,
        'is_active' => true,
    ]);

    $transaction = Transaction::create([
        'transaction_id' => 'tx-verify-1',
        'payment_provider_id' => $provider->id,
        'provider_transaction_id' => 'LENCO-REF-1',
        'customer_id' => $customer->id,
        'amount' => 50,
        'currency' => 'ZMW',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
        ...$overrides,
    ]);

    return [$provider, $transaction];
}

test('verifying a transaction updates its status from the provider', function () {
    Http::fake(['*/collections/status/*' => Http::response(['status' => true, 'data' => ['status' => 'successful']], 200)]);

    $user = User::factory()->create(['api_token' => 'tok']);
    [, $transaction] = lencoTransaction($user);

    $this->withToken('tok')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJson(['status' => 'success', 'data' => ['status' => 'success', 'provider_status' => 'successful']]);

    expect($transaction->fresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('a settled transaction with a callback url dispatches a single callback', function () {
    Queue::fake();
    Http::fake(['*/collections/status/*' => Http::response(['status' => true, 'data' => ['status' => 'failed']], 200)]);

    $user = User::factory()->create(['api_token' => 'tok']);
    [, $transaction] = lencoTransaction($user, ['callback_url' => 'https://merchant.test/webhook']);

    $this->withToken('tok')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::FAILED);
    Queue::assertPushed(SendTransactionCallback::class, 1);
});

test('no callback is dispatched without a callback url', function () {
    Queue::fake();
    Http::fake(['*/collections/status/*' => Http::response(['status' => true, 'data' => ['status' => 'successful']], 200)]);

    $user = User::factory()->create(['api_token' => 'tok']);
    [, $transaction] = lencoTransaction($user); // no callback_url

    $this->withToken('tok')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk();

    Queue::assertNotPushed(SendTransactionCallback::class);
});

test('no callback is dispatched while the transaction is still pending', function () {
    Queue::fake();
    Http::fake(['*/collections/status/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']], 200)]);

    $user = User::factory()->create(['api_token' => 'tok']);
    [, $transaction] = lencoTransaction($user, ['callback_url' => 'https://merchant.test/webhook']);

    $this->withToken('tok')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk();

    expect($transaction->fresh()->status)->toBe(TransactionStatus::PENDING);
    Queue::assertNotPushed(SendTransactionCallback::class);
});

test('an already-notified transaction is not notified again', function () {
    Queue::fake();
    Http::fake(['*/collections/status/*' => Http::response(['status' => true, 'data' => ['status' => 'successful']], 200)]);

    $user = User::factory()->create(['api_token' => 'tok']);
    [, $transaction] = lencoTransaction($user, [
        'callback_url' => 'https://merchant.test/webhook',
        'callback_notified_at' => now(),
    ]);

    $this->withToken('tok')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk();

    Queue::assertNotPushed(SendTransactionCallback::class);
});

test('a transaction cannot be verified by another account', function () {
    Http::fake(['*/collections/status/*' => Http::response(['status' => true, 'data' => ['status' => 'successful']], 200)]);

    $owner = User::factory()->create();
    $intruder = User::factory()->create(['api_token' => 'intruder-tok']);
    [, $transaction] = lencoTransaction($owner);

    $this->withToken('intruder-tok')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertStatus(404);
});

test('the callback job posts the result and marks it delivered', function () {
    Http::fake(['https://merchant.test/*' => Http::response([], 200)]);

    $user = User::factory()->create();
    [, $transaction] = lencoTransaction($user, [
        'status' => TransactionStatus::SUCCESS,
        'callback_url' => 'https://merchant.test/webhook',
    ]);

    (new SendTransactionCallback($transaction))->handle();

    Http::assertSent(fn ($request) => $request->url() === 'https://merchant.test/webhook'
        && $request['transaction_id'] === $transaction->transaction_id
        && $request['status'] === 'success');

    expect($transaction->fresh()->callback_notified_at)->not->toBeNull();
});

test('the request to pay stores an optional callback url', function () {
    Http::fake([
        '*/resolve/mobile-money' => Http::response(['status' => true, 'data' => ['accountName' => 'Jane Doe', 'operator' => 'mtn', 'country' => 'zm']], 200),
        '*/collections/mobile-money' => Http::response(['status' => true, 'data' => ['lencoReference' => 'LENCO-NEW']], 200),
    ]);

    $user = User::factory()->create(['api_token' => 'tok']);
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'config' => ['api_key' => 'lenco_key'],
        'class' => LencoController::class,
        'is_active' => true,
    ]);

    $this->withToken('tok')
        ->postJson('/api/v1/payment/request', [
            'amount' => 10,
            'account_number' => '0971000000',
            'country' => 'ZM',
            'callback_url' => 'https://merchant.test/webhook',
        ])->assertOk();

    $this->assertDatabaseHas('transactions', ['callback_url' => 'https://merchant.test/webhook']);
});

test('an invalid callback url is rejected', function () {
    $user = User::factory()->create(['api_token' => 'tok']);

    $this->withToken('tok')
        ->postJson('/api/v1/payment/request', [
            'amount' => 10,
            'account_number' => '0971000000',
            'country' => 'ZM',
            'callback_url' => 'not-a-url',
        ])->assertStatus(422);
});
