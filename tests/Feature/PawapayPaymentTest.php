<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\PawapayController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function pawapayProvider(User $user): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'pawaPay',
        'class' => PawapayController::class,
        'config' => ['api_token' => 'pp-token', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
}

function fakePawapayPredict(string $correspondent = 'MTN_MOMO_ZMB'): array
{
    return ['*/v1/predict-correspondent' => Http::response([
        'country' => 'ZMB', 'operator' => 'MTN', 'correspondent' => $correspondent, 'msisdn' => '260763456789',
    ], 200)];
}

test('pawapay covers its official 20 markets', function () {
    expect(PawapayController::SUPPORTED_COUNTRIES)
        ->toHaveCount(20)
        ->toContain('SL', 'SN', 'BF', 'ET', 'LS', 'MZ');
});

test('the pawapay driver predicts the operator and initiates a deposit', function () {
    $user = User::factory()->create(['api_token' => 'pp-user']);
    pawapayProvider($user);

    Http::fake([
        ...fakePawapayPredict(),
        '*/deposits' => Http::response(['depositId' => 'x', 'status' => 'ACCEPTED', 'created' => '2026-01-01T00:00:00Z'], 200),
    ]);

    $this->withToken('pp-user')
        ->postJson('/api/v1/payment/request', ['amount' => 750, 'account_number' => '0763456789', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('transactions', [
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING->value,
    ]);

    // The predict call runs first, then the deposit carries the predicted
    // correspondent, the E.164 msisdn, a UUID depositId and a Bearer token.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/predict-correspondent')
        && $request['msisdn'] === '260763456789');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/deposits')
        && $request->hasHeader('Authorization', 'Bearer pp-token')
        && $request['correspondent'] === 'MTN_MOMO_ZMB'
        && $request['currency'] === 'ZMW'
        && $request['amount'] === '750'
        && $request['payer']['type'] === 'MSISDN'
        && $request['payer']['address']['value'] === '260763456789');
});

test('the pawapay driver honours a caller-supplied correspondent without predicting', function () {
    $user = User::factory()->create(['api_token' => 'pp-user-2']);
    pawapayProvider($user);

    Http::fake([
        '*/deposits' => Http::response(['status' => 'ACCEPTED'], 200),
    ]);

    $this->withToken('pp-user-2')
        ->postJson('/api/v1/payment/request', [
            'amount' => 100,
            'account_number' => '0977123456',
            'country' => 'ZM',
            'correspondent' => 'AIRTEL_OAPI_ZMB',
        ])
        ->assertOk();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/predict-correspondent'));
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/deposits')
        && $request['correspondent'] === 'AIRTEL_OAPI_ZMB');
});

test('the pawapay driver errors when the operator cannot be determined', function () {
    $user = User::factory()->create(['api_token' => 'pp-user-3']);
    pawapayProvider($user);

    Http::fake([
        '*/v1/predict-correspondent' => Http::response(['message' => 'unknown'], 422),
    ]);

    $this->withToken('pp-user-3')
        ->postJson('/api/v1/payment/request', ['amount' => 100, 'account_number' => '0763456789', 'country' => 'ZM'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');
});

test('the pawapay driver surfaces a rejected deposit as an error', function () {
    $user = User::factory()->create(['api_token' => 'pp-user-4']);
    pawapayProvider($user);

    Http::fake([
        ...fakePawapayPredict(),
        '*/deposits' => Http::response([
            'status' => 'REJECTED',
            'rejectionReason' => ['rejectionCode' => 'INVALID_AMOUNT', 'rejectionMessage' => 'Amount is invalid'],
        ], 200),
    ]);

    $this->withToken('pp-user-4')
        ->postJson('/api/v1/payment/request', ['amount' => 750, 'account_number' => '0763456789', 'country' => 'ZM'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Amount is invalid');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});

function pendingPawapayTransaction(User $user): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-pp@example.com']);

    return Transaction::create([
        'transaction_id' => '8917c345-4791-4285-a416-62f24b6982db',
        'payment_provider_id' => pawapayProvider($user)->id,
        'provider_transaction_id' => '8917c345-4791-4285-a416-62f24b6982db',
        'customer_id' => $customer->id,
        'amount' => 750,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
    ]);
}

test('the pawapay driver maps a COMPLETED deposit to success on verification', function () {
    $user = User::factory()->create(['api_token' => 'pp-verify']);
    $transaction = pendingPawapayTransaction($user);

    Http::fake([
        '*/deposits/*' => Http::response([[
            'depositId' => $transaction->transaction_id,
            'status' => 'COMPLETED',
            'depositedAmount' => '750.00',
            'currency' => 'ZMW',
        ]], 200),
    ]);

    $this->withToken('pp-verify')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', 'COMPLETED');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('the pawapay driver maps a FAILED deposit to failed on verification', function () {
    $user = User::factory()->create(['api_token' => 'pp-verify-2']);
    $transaction = pendingPawapayTransaction($user);

    Http::fake([
        '*/deposits/*' => Http::response([[
            'depositId' => $transaction->transaction_id,
            'status' => 'FAILED',
            'failureReason' => ['failureCode' => 'PAYMENT_NOT_APPROVED', 'failureMessage' => 'Payment not approved'],
        ]], 200),
    ]);

    $this->withToken('pp-verify-2')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});
