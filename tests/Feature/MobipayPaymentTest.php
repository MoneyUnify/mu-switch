<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\MobipayController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function mobipayProvider(User $user): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'MobiPay',
        'class' => MobipayController::class,
        'config' => ['api_key' => 'mp-key', 'app_id' => '456475567', 'supported_countries' => ['MW']],
        'is_active' => true,
    ]);
}

function mobipayRequestOk(): array
{
    return ['*/api/v1/paymentrequest' => Http::response([
        'message' => 'Payment request sent',
        'data' => ['transaction_id' => 'MP-777', 'merchant_trx_id' => 'ref', 'wallet' => '265991234567', 'amount' => 500],
    ], 201)];
}

test('mobipay is a malawi-only mobile money provider', function () {
    expect(MobipayController::SUPPORTED_COUNTRIES)->toBe(['MW']);
});

test('the mobipay driver pushes an Airtel Money request in Malawi', function () {
    $user = User::factory()->create(['api_token' => 'mp-airtel']);
    mobipayProvider($user);

    Http::fake(mobipayRequestOk());

    // 099x is an Airtel Malawi prefix -> bankId 1.
    $this->withToken('mp-airtel')
        ->postJson('/api/v1/payment/request', ['amount' => 500, 'account_number' => '0991234567', 'country' => 'MW'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'MP-777',
        'currency' => 'MWK',
        'country' => 'MW',
        'status' => TransactionStatus::PENDING->value,
    ]);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/paymentrequest')
        && $request->hasHeader('x-api-key', 'mp-key')
        && $request->hasHeader('x-app-id', '456475567')
        && $request['bankId'] === 1
        && $request['customerPhone'] === '265991234567'
        && $request['amount'] === 500);
});

test('the mobipay driver routes a TNM Mpamba number to bankId 2', function () {
    $user = User::factory()->create(['api_token' => 'mp-tnm']);
    mobipayProvider($user);

    Http::fake(mobipayRequestOk());

    // 088x is a TNM Malawi prefix -> bankId 2.
    $this->withToken('mp-tnm')
        ->postJson('/api/v1/payment/request', ['amount' => 1200, 'account_number' => '0881234567', 'country' => 'MW'])
        ->assertOk();

    Http::assertSent(fn ($request) => $request['bankId'] === 2
        && $request['customerPhone'] === '265881234567');
});

test('the mobipay driver surfaces a rejected request as an error', function () {
    $user = User::factory()->create(['api_token' => 'mp-fail']);
    mobipayProvider($user);

    Http::fake([
        '*/api/v1/paymentrequest' => Http::response(['message' => 'Invalid wallet'], 400),
    ]);

    $this->withToken('mp-fail')
        ->postJson('/api/v1/payment/request', ['amount' => 500, 'account_number' => '0991234567', 'country' => 'MW'])
        ->assertStatus(400)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Invalid wallet');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});

function pendingMobipayTransaction(User $user): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-mp@example.com']);

    return Transaction::create([
        'transaction_id' => 'MU-mp-ref-1',
        'payment_provider_id' => mobipayProvider($user)->id,
        'provider_transaction_id' => 'MP-777',
        'customer_id' => $customer->id,
        'amount' => 500,
        'currency' => 'MWK',
        'country' => 'MW',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
    ]);
}

test('the mobipay driver maps a Completed enquiry to success', function () {
    $user = User::factory()->create(['api_token' => 'mp-verify']);
    $transaction = pendingMobipayTransaction($user);

    Http::fake([
        '*/api/v1/payment/enquire/*' => Http::response(['data' => ['status' => 'Completed']], 200),
    ]);

    $this->withToken('mp-verify')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', 'Completed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);

    // The enquiry is keyed on our merchant transaction reference.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/v1/payment/enquire/MU-mp-ref-1'));
});

test('the mobipay driver maps a Failed enquiry to failed', function () {
    $user = User::factory()->create(['api_token' => 'mp-verify-2']);
    $transaction = pendingMobipayTransaction($user);

    Http::fake([
        '*/api/v1/payment/enquire/*' => Http::response(['data' => ['status' => 'Failed']], 200),
    ]);

    $this->withToken('mp-verify-2')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});
