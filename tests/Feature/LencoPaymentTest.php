<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\LencoController;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('lenco provider requests payment successfully', function () {
    Http::fake([
        '*/resolve/mobile-money' => Http::response([
            'status' => true,
            'message' => 'Resolved',
            'data' => [
                'type' => 'mobile-money',
                'accountName' => 'John Doe',
                'phone' => '761234567',
                'operator' => 'mtn',
                'country' => 'zm',
            ],
        ], 200),
        '*/collections/mobile-money' => Http::response([
            'status' => true,
            'message' => 'Payment initiated',
            'data' => [
                'id' => 'lenco-id-123',
                'reference' => 'internal-ref-123',
                'lencoReference' => 'LENCO-REF-999',
                'status' => 'pay-offline',
                'type' => 'mobile-money',
            ],
        ], 200),
    ]);

    $user = User::factory()->create([
        'api_token' => 'my-secure-api-token',
    ]);

    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco Provider',
        'config' => ['api_key' => 'lenco_key_123'],
        'class' => LencoController::class,
        'is_active' => true,
    ]);

    $response = $this->withToken('my-secure-api-token')
        ->postJson('/api/v1/payment/request', [
            'amount' => 150.50,
            'account_number' => '761234567',
            'country' => 'ZM',
        ]);

    $response->assertOk();
    $response->assertJson([
        'status' => 'success',
        'message' => 'Payment request initiated successfully',
    ]);

    $data = $response->json('data');
    expect($data)->toHaveKey('transaction_id');
    expect($data['reference'])->toBe('LENCO-REF-999');

    // Assert database states
    $this->assertDatabaseHas('customers', [
        'name' => 'John Doe',
        'email' => '761234567@moneyunify.local',
    ]);

    $this->assertDatabaseHas('customer_accounts', [
        'name' => 'John Doe',
        'number' => '761234567',
        'country' => 'ZM',
    ]);

    $this->assertDatabaseHas('transactions', [
        'transaction_id' => $data['transaction_id'],
        'payment_provider_id' => $provider->id,
        'provider_transaction_id' => 'LENCO-REF-999',
        'amount' => 150.50,
        'currency' => 'ZMW',
        'status' => TransactionStatus::PENDING->value,
    ]);
});

test('repeated payment requests for the same number do not violate the unique customer email', function () {
    Http::fake([
        '*/resolve/mobile-money' => Http::response([
            'status' => true,
            'message' => 'Resolved',
            'data' => ['accountName' => 'Blessed Mwanza', 'operator' => 'mtn', 'country' => 'zm'],
        ], 200),
        // Real providers return a unique reference per call.
        '*/collections/mobile-money' => Http::sequence()
            ->push(['status' => true, 'data' => ['lencoReference' => 'LENCO-REF-1']], 200)
            ->push(['status' => true, 'data' => ['lencoReference' => 'LENCO-REF-2']], 200),
    ]);

    $user = User::factory()->create(['api_token' => 'token-repeat']);
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco Provider',
        'config' => ['api_key' => 'lenco_key'],
        'class' => LencoController::class,
        'is_active' => true,
    ]);

    $payload = ['amount' => 10, 'account_number' => '0971943638', 'country' => 'ZM'];

    // The same payer pays twice — previously the second attempt threw a unique
    // constraint violation on customers.email.
    $this->withToken('token-repeat')->postJson('/api/v1/payment/request', $payload)->assertOk();
    $this->withToken('token-repeat')->postJson('/api/v1/payment/request', $payload)->assertOk();

    expect(Customer::where('email', '0971943638@moneyunify.local')->count())->toBe(1);
    expect(CustomerAccount::where('number', '0971943638')->where('country', 'ZM')->count())->toBe(1);
    expect(Transaction::count())->toBe(2);
});

test('a pre-existing customer with the same email does not break a new payment', function () {
    Http::fake([
        '*/resolve/mobile-money' => Http::response([
            'status' => true,
            'data' => ['accountName' => 'Blessed Mwanza', 'operator' => 'mtn', 'country' => 'zm'],
        ], 200),
        '*/collections/mobile-money' => Http::response([
            'status' => true,
            'data' => ['lencoReference' => 'LENCO-REF-2'],
        ], 200),
    ]);

    Customer::create(['name' => 'Old Name', 'email' => '0971943638@moneyunify.local']);

    $user = User::factory()->create(['api_token' => 'token-pre']);
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco Provider',
        'config' => ['api_key' => 'lenco_key'],
        'class' => LencoController::class,
        'is_active' => true,
    ]);

    $this->withToken('token-pre')
        ->postJson('/api/v1/payment/request', ['amount' => 10, 'account_number' => '0971943638', 'country' => 'ZM'])
        ->assertOk();

    // The resolved name should update the existing customer record.
    $this->assertDatabaseHas('customers', [
        'email' => '0971943638@moneyunify.local',
        'name' => 'Blessed Mwanza',
    ]);
});

test('lenco provider fails if api returns error', function () {
    Http::fake([
        '*/resolve/mobile-money' => Http::response([
            'status' => true,
            'message' => 'Resolved',
            'data' => [
                'type' => 'mobile-money',
                'accountName' => 'John Doe',
                'phone' => '761234567',
                'operator' => 'mtn',
                'country' => 'zm',
            ],
        ], 200),
        '*/collections/mobile-money' => Http::response([
            'status' => false,
            'message' => 'Insufficient funds or generic error',
            'data' => null,
        ], 400),
    ]);

    $user = User::factory()->create([
        'api_token' => 'my-secure-api-token',
    ]);

    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco Provider',
        'config' => ['api_key' => 'lenco_key_123'],
        'class' => LencoController::class,
        'is_active' => true,
    ]);

    $response = $this->withToken('my-secure-api-token')
        ->postJson('/api/v1/payment/request', [
            'amount' => 150.50,
            'account_number' => '761234567',
            'country' => 'ZM',
        ]);

    $response->assertStatus(400);
    $response->assertJson([
        'status' => 'error',
        'message' => 'Insufficient funds or generic error',
    ]);

    // Transaction should be logged as failed in db
    $this->assertDatabaseHas('transactions', [
        'status' => TransactionStatus::FAILED->value,
        'amount' => 150.50,
    ]);
});
