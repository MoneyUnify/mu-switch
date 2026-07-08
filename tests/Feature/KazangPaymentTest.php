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

function kazangProvider(User $user, array $configOverrides = []): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Kazang',
        'class' => KazangController::class,
        'config' => array_merge([
            'username' => 'api-user',
            'password' => 'api-pass',
            'channel' => 'web',
            'host' => 'testapi.kazang.net',
            'mtn_product_id' => '2001',
            'airtel_product_id' => '2002',
            'zamtel_product_id' => '2003',
            'supported_countries' => ['ZM'],
        ], $configOverrides),
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

test('the kazang driver rejects an unknown operator prefix', function () {
    $user = User::factory()->create(['api_token' => 'kz-unknown']);
    kazangProvider($user);

    Http::fake(kzAuthOk());

    // 060x is not an MTN, Airtel or Zamtel Zambia prefix.
    $this->withToken('kz-unknown')
        ->postJson('/api/v1/payment/request', ['amount' => 5, 'account_number' => '0605123456', 'country' => 'ZM'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');

    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api_rest/v1/mtnDebit'));
});

test('the kazang driver completes a Zamtel payment on a confirmed receipt', function () {
    $user = User::factory()->create(['api_token' => 'kz-zamtel']);
    kazangProvider($user);

    Http::fake([
        ...kzAuthOk(),
        '*/api_rest/v1/zamtelMoneyPay' => Http::response(['response_code' => '0', 'confirmation_number' => '444'], 200),
        '*/api_rest/v1/zamtelMoneyPayConfirm' => Http::response(['response_code' => '0', 'zamtel_reference' => '000000108591', 'transaction_reference_str' => '63190'], 200),
    ]);

    // 095x is a Zamtel Zambia prefix; the confirmed receipt is final.
    $this->withToken('kz-zamtel')
        ->postJson('/api/v1/payment/request', ['amount' => 12.73, 'account_number' => '0955123456', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'success');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => '000000108591',
        'currency' => 'ZMW',
        'status' => TransactionStatus::SUCCESS->value,
    ]);

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api_rest/v1/zamtelMoneyPay')
        && $request['product_id'] === 2003
        && $request['amount'] === '1273'
        && $request['msisdn'] === '260955123456');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api_rest/v1/zamtelMoneyPayConfirm')
        && $request['confirmation_number'] === '444');
});

test('the kazang driver keeps an unconfirmed Zamtel payment pending and settles it on verify', function () {
    $user = User::factory()->create(['api_token' => 'kz-zamtel-2']);
    kazangProvider($user);

    Http::fake([
        ...kzAuthOk(),
        '*/api_rest/v1/zamtelMoneyPay' => Http::response(['response_code' => '0', 'confirmation_number' => '445'], 200),
        // The payer hasn't entered their PIN on the Zamtel USSD prompt yet.
        '*/api_rest/v1/zamtelMoneyPayConfirm' => Http::sequence()
            ->push(['response_code' => '19', 'response_message' => 'Busy processing, please wait ...'], 200)
            ->push(['response_code' => '0', 'zamtel_reference' => '000000108592'], 200),
    ]);

    $response = $this->withToken('kz-zamtel-2')
        ->postJson('/api/v1/payment/request', ['amount' => 5, 'account_number' => '0955123456', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $transactionId = $response->json('data.transaction_id');

    // Verification retries the confirm with the stored confirmation number.
    $this->withToken('kz-zamtel-2')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transactionId])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $this->assertDatabaseHas('transactions', [
        'transaction_id' => $transactionId,
        'status' => TransactionStatus::SUCCESS->value,
    ]);
});

test('the kazang driver runs a configured market through the same envelope', function () {
    $user = User::factory()->create(['api_token' => 'kz-na']);
    kazangProvider($user, [
        'supported_countries' => ['ZM', 'NA'],
        'market_operators' => [
            'NA' => [
                'product_id' => '7001',
                'pay_method' => 'mtcMarisPay',
                'pay_confirm_method' => 'mtcMarisPayConfirm',
                'query_method' => '',
                'query_confirm_method' => '',
                'msisdn_param' => '',
                'reference_field' => '',
            ],
        ],
    ]);

    Http::fake([
        ...kzAuthOk(),
        '*/api_rest/v1/mtcMarisPay' => Http::response(['response_code' => '0', 'confirmation_number' => '555'], 200),
        '*/api_rest/v1/mtcMarisPayConfirm' => Http::response(['response_code' => '0', 'transaction_reference_str' => 'MTC-64293'], 200),
    ]);

    // No query method configured: the confirmed receipt is final.
    $this->withToken('kz-na')
        ->postJson('/api/v1/payment/request', ['amount' => 100, 'account_number' => '0817363450', 'country' => 'NA'])
        ->assertOk()
        ->assertJsonPath('data.status', 'success');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'MTC-64293',
        'currency' => 'NAD',
        'country' => 'NA',
        'status' => TransactionStatus::SUCCESS->value,
    ]);

    // Same session envelope, Namibian E.164 msisdn, defaults applied.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api_rest/v1/mtcMarisPay')
        && $request['session_uuid'] === 'sess-123'
        && $request['product_id'] === 7001
        && $request['amount'] === '10000'
        && $request['wallet_msisdn'] === '264817363450');
});

test('a configured market with a query method settles on verification', function () {
    $user = User::factory()->create(['api_token' => 'kz-bw']);
    kazangProvider($user, [
        'supported_countries' => ['ZM', 'BW'],
        'market_operators' => [
            'BW' => [
                'product_id' => '8001',
                'pay_method' => 'orangeMoneyPay',
                'pay_confirm_method' => 'orangeMoneyPayConfirm',
                'query_method' => 'orangeMoneyPayQuery',
                'query_confirm_method' => 'orangeMoneyPayQueryConfirm',
                'msisdn_param' => 'msisdn',
                'reference_field' => 'orange_reference',
            ],
        ],
    ]);

    Http::fake([
        ...kzAuthOk(),
        '*/api_rest/v1/orangeMoneyPay' => Http::response(['response_code' => '0', 'confirmation_number' => '666'], 200),
        '*/api_rest/v1/orangeMoneyPayConfirm' => Http::response(['response_code' => '0', 'orange_reference' => 'OM-777'], 200),
        '*/api_rest/v1/orangeMoneyPayQuery' => Http::response(['response_code' => '0', 'confirmation_number' => '667'], 200),
        '*/api_rest/v1/orangeMoneyPayQueryConfirm' => Http::response(['response_code' => '0'], 200),
    ]);

    // A query method is configured, so the push stays pending until verified.
    $response = $this->withToken('kz-bw')
        ->postJson('/api/v1/payment/request', ['amount' => 50, 'account_number' => '071234567', 'country' => 'BW'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $transactionId = $response->json('data.transaction_id');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'OM-777',
        'currency' => 'BWP',
        'status' => TransactionStatus::PENDING->value,
    ]);

    $this->withToken('kz-bw')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transactionId])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    // The query carries the custom msisdn param and reference field.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api_rest/v1/orangeMoneyPayQuery')
        && $request['msisdn'] === '26771234567'
        && $request['orange_reference'] === 'OM-777');
});

test('a configured-market country without its integration is rejected', function () {
    $user = User::factory()->create(['api_token' => 'kz-na-missing']);
    kazangProvider($user, ['supported_countries' => ['ZM', 'NA']]);

    Http::fake(kzAuthOk());

    $this->withToken('kz-na-missing')
        ->postJson('/api/v1/payment/request', ['amount' => 10, 'account_number' => '0817363450', 'country' => 'NA'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');
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
