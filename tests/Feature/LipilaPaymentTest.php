<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\LipilaController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function lipilaProvider(User $user): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lipila',
        'class' => LipilaController::class,
        'config' => ['api_key' => 'lp-secret', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
}

test('the lipila driver initiates a mobile-money collection', function () {
    $user = User::factory()->create(['api_token' => 'lipila-token']);
    lipilaProvider($user);

    Http::fake([
        '*/api/v1/collections/mobile-money' => Http::response([
            'currency' => 'ZMW',
            'amount' => 30,
            'accountNumber' => '260977123456',
            'status' => 'Pending',
            'paymentType' => 'AirtelMoney',
            'referenceId' => 'abc123def456',
            'identifier' => 'LPLXC-20251001-2834',
            'message' => 'Transaction Successful',
        ], 200),
    ]);

    $this->withToken('lipila-token')
        ->postJson('/api/v1/payment/request', ['amount' => 30, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'LPLXC-20251001-2834',
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING->value,
    ]);

    // The required body params are all present and sent as JSON with the API key.
    // Email is optional, so it must NOT be sent when the caller omits it.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/collections/mobile-money')
        && $request->hasHeader('x-api-key', 'lp-secret')
        && $request->hasHeader('Content-Type', 'application/json')
        && $request['referenceId'] !== null
        && $request['currency'] === 'ZMW'
        && $request['amount'] === 30.0
        && $request['accountNumber'] === '260977123456'
        && $request['narration'] === 'Payment collection'
        && ! array_key_exists('email', $request->data()));
});

test('the lipila driver forwards the payer email only when supplied', function () {
    $user = User::factory()->create(['api_token' => 'lipila-email']);
    lipilaProvider($user);

    Http::fake([
        '*/api/v1/collections/mobile-money' => Http::response([
            'status' => 'Pending',
            'referenceId' => 'abc123def456',
            'identifier' => 'LPLXC-1',
        ], 200),
    ]);

    $this->withToken('lipila-email')
        ->postJson('/api/v1/payment/request', [
            'amount' => 30,
            'account_number' => '0977123456',
            'country' => 'ZM',
            'email' => 'payer@example.com',
        ])
        ->assertOk();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/collections/mobile-money')
        && $request['email'] === 'payer@example.com');
});

test('the lipila driver surfaces a declined collection as an error', function () {
    $user = User::factory()->create(['api_token' => 'lipila-token-2']);
    lipilaProvider($user);

    Http::fake([
        '*/api/v1/collections/mobile-money' => Http::response([
            'status' => 'Failed',
            'message' => 'Insufficient balance',
        ], 400),
    ]);

    $this->withToken('lipila-token-2')
        ->postJson('/api/v1/payment/request', ['amount' => 30, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Insufficient balance');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});

function pendingLipilaTransaction(User $user): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-lipila@example.com']);

    return Transaction::create([
        'transaction_id' => 'lip-ref-123',
        'payment_provider_id' => lipilaProvider($user)->id,
        'provider_transaction_id' => 'LPLXC-1',
        'customer_id' => $customer->id,
        'amount' => 30,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
    ]);
}

test('the lipila driver maps a Successful status to success on verification', function () {
    $user = User::factory()->create(['api_token' => 'lipila-verify']);
    $transaction = pendingLipilaTransaction($user);

    Http::fake([
        '*/api/v1/collections/check-status*' => Http::response([
            'referenceId' => 'lip-ref-123',
            'status' => 'Successful',
            'identifier' => 'LPLTXN-1',
            'message' => 'Transaction Successful',
        ], 200),
    ]);

    $this->withToken('lipila-verify')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', 'Successful');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);

    // The referenceId (our transaction_id) is passed as a query parameter.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/collections/check-status')
        && $request['referenceId'] === 'lip-ref-123'
        && $request->hasHeader('x-api-key', 'lp-secret'));
});

test('the lipila driver maps a Failed status to failed on verification', function () {
    $user = User::factory()->create(['api_token' => 'lipila-verify-2']);
    $transaction = pendingLipilaTransaction($user);

    Http::fake([
        '*/api/v1/collections/check-status*' => Http::response([
            'referenceId' => 'lip-ref-123',
            'status' => 'Failed',
            'message' => 'PIN entry timed out',
        ], 200),
    ]);

    $this->withToken('lipila-verify-2')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});
