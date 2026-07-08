<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\KazangController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

function kazangProvider(User $user): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Kazang',
        'class' => KazangController::class,
        'config' => [
            'username' => 'api-user',
            'password' => 'api-pass',
            'channel' => 'web',
            'host' => 'testapi.kazang.net',
            'mtn_product_id' => '2001',
            'airtel_product_id' => '2002',
            'supported_countries' => ['ZM'],
        ],
        'is_active' => true,
    ]);
}

function kzAuthOk(): array
{
    return ['*/api_rest/v1/authClient' => Http::response(['response_code' => '0', 'session_uuid' => 'sess-123', 'balance' => '5000.00'], 200)];
}

test('the kazang driver logs in then pushes an MTN wallet debit', function () {
    $user = User::factory()->create(['api_token' => 'kz-mtn']);
    kazangProvider($user);

    Http::fake([
        ...kzAuthOk(),
        '*/api_rest/v1/mtnDebit' => Http::response([
            'response_code' => '0',
            'transaction_reference' => 987654,
            'supplier_transaction_id' => 'MTNREF-001',
            'response_message' => 'Pending Payment Created',
        ], 200),
    ]);

    // 096x is an MTN Zambia prefix.
    $this->withToken('kz-mtn')
        ->postJson('/api/v1/payment/request', ['amount' => 5, 'account_number' => '0966123456', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'MTNREF-001',
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING->value,
    ]);

    // Authenticated, then debited with the amount in ngwee (K5.00 => "500").
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api_rest/v1/authClient')
        && $request['username'] === 'api-user' && $request['channel'] === 'web');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api_rest/v1/mtnDebit')
        && $request['session_uuid'] === 'sess-123'
        && $request['product_id'] === 2001
        && $request['amount'] === '500'
        && $request['wallet_msisdn'] === '260966123456');
});

test('the kazang driver pushes an Airtel payment request via initiate + confirm', function () {
    $user = User::factory()->create(['api_token' => 'kz-airtel']);
    kazangProvider($user);

    Http::fake([
        ...kzAuthOk(),
        '*/api_rest/v1/airtelPayPayment' => Http::response(['response_code' => '0', 'confirmation_number' => '111'], 200),
        '*/api_rest/v1/airtelPayPaymentConfirm' => Http::response(['response_code' => '0', 'airtel_reference' => 'AIR-777', 'transaction_reference' => 5555], 200),
    ]);

    // 097x is an Airtel Zambia prefix.
    $this->withToken('kz-airtel')
        ->postJson('/api/v1/payment/request', ['amount' => 12.5, 'account_number' => '0971234567', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'AIR-777',
        'currency' => 'ZMW',
        'status' => TransactionStatus::PENDING->value,
    ]);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api_rest/v1/airtelPayPayment')
        && $request['amount'] === '1250'
        && $request['wallet_msisdn'] === '260971234567');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api_rest/v1/airtelPayPaymentConfirm')
        && $request['confirmation_number'] === '111');
});

test('the kazang driver surfaces a failed debit as an error', function () {
    $user = User::factory()->create(['api_token' => 'kz-fail']);
    kazangProvider($user);

    Http::fake([
        ...kzAuthOk(),
        '*/api_rest/v1/mtnDebit' => Http::response(['response_code' => '79', 'response_message' => 'MTN Debit Failed'], 200),
    ]);

    $this->withToken('kz-fail')
        ->postJson('/api/v1/payment/request', ['amount' => 5, 'account_number' => '0966123456', 'country' => 'ZM'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'MTN Debit Failed');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});

test('the kazang driver rejects an unsupported operator prefix', function () {
    $user = User::factory()->create(['api_token' => 'kz-zamtel']);
    kazangProvider($user);

    Http::fake(kzAuthOk());

    // 095x is Zamtel — not one of the request-to-pay operators this driver handles.
    $this->withToken('kz-zamtel')
        ->postJson('/api/v1/payment/request', ['amount' => 5, 'account_number' => '0955123456', 'country' => 'ZM'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api_rest/v1/mtnDebit'));
});

function pendingKazangTransaction(User $user, string $operator, string $reference): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => "jane-kz-{$operator}@example.com"]);

    return Transaction::create([
        'transaction_id' => "MU-kz-{$operator}",
        'payment_provider_id' => kazangProvider($user)->id,
        'provider_transaction_id' => $reference,
        'customer_id' => $customer->id,
        'amount' => 5,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
        'provider_response' => ['operator' => $operator, 'reference' => $reference, 'msisdn' => '260966123456', 'amount_minor' => 500],
    ]);
}

test('the kazang driver settles an approved MTN debit to success', function () {
    $user = User::factory()->create(['api_token' => 'kz-verify-mtn']);
    $transaction = pendingKazangTransaction($user, 'mtn', 'MTNREF-001');

    Http::fake([
        ...kzAuthOk(),
        '*/api_rest/v1/mtnDebitApproval' => Http::response(['response_code' => '0', 'confirmation_number' => '222'], 200),
        '*/api_rest/v1/mtnDebitApprovalConfirm' => Http::response(['response_code' => '0', 'transaction_reference' => 987654], 200),
    ]);

    $this->withToken('kz-verify-mtn')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api_rest/v1/mtnDebitApproval')
        && $request['supplier_transaction_id'] === 'MTNREF-001'
        && $request['amount'] === '500');
});

test('the kazang driver keeps an unapproved payment pending', function () {
    $user = User::factory()->create(['api_token' => 'kz-verify-pending']);
    $transaction = pendingKazangTransaction($user, 'mtn', 'MTNREF-002');

    Http::fake([
        ...kzAuthOk(),
        // Payer has not approved on their handset yet.
        '*/api_rest/v1/mtnDebitApproval' => Http::response(['response_code' => '19', 'response_message' => 'Busy processing, please wait ...'], 200),
    ]);

    $this->withToken('kz-verify-pending')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'pending');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING);
});

test('the kazang driver settles an approved Airtel payment to success', function () {
    $user = User::factory()->create(['api_token' => 'kz-verify-airtel']);
    $transaction = pendingKazangTransaction($user, 'airtel', 'AIR-777');

    Http::fake([
        ...kzAuthOk(),
        '*/api_rest/v1/airtelPayQuery' => Http::response(['response_code' => '0', 'confirmation_number' => '333'], 200),
        '*/api_rest/v1/airtelPayQueryConfirm' => Http::response(['response_code' => '0', 'airtel_receipt_number' => 'MP-XYZ'], 200),
    ]);

    $this->withToken('kz-verify-airtel')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});
