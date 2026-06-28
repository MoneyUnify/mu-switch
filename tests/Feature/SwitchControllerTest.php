<?php

use App\Http\Controllers\Providers\AirtelController;
use App\Http\Controllers\Providers\LencoController;
use App\Http\Controllers\Providers\MtnController;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

test('switch controller filters out providers not supporting the requested country', function () {
    $user = User::factory()->create([
        'api_token' => 'switch-test-token',
    ]);

    // Provider 1: Lenco supports MW only (requested is ZM, so this should be filtered out)
    $provider1 = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco MW Only',
        'config' => ['api_key' => 'key_mw', 'supported_countries' => 'MW'],
        'class' => LencoController::class,
        'is_active' => true,
    ]);

    // Provider 2: Lenco supports ZM only (should be invoked)
    $provider2 = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco ZM Only',
        'config' => ['api_key' => 'key_zm', 'supported_countries' => ['ZM']],
        'class' => LencoController::class,
        'is_active' => true,
    ]);

    // Mock resolve & collection endpoints for Lenco ZM Only
    Http::fake([
        '*/resolve/mobile-money' => Http::response([
            'status' => true,
            'message' => 'Resolved',
            'data' => [
                'type' => 'mobile-money',
                'accountName' => 'Alice',
                'phone' => '769999999',
                'operator' => 'mtn',
                'country' => 'zm',
            ],
        ], 200),
        '*/collections/mobile-money' => Http::response([
            'status' => true,
            'message' => 'Payment initiated',
            'data' => [
                'id' => 'lenco-id-abc',
                'reference' => 'internal-ref-abc',
                'lencoReference' => 'LENCO-SUCCESS-REF',
                'status' => 'pay-offline',
                'type' => 'mobile-money',
            ],
        ], 200),
    ]);

    $response = $this->withToken('switch-test-token')
        ->postJson('/api/v1/payment/request', [
            'amount' => 50.00,
            'account_number' => '769999999',
            'country' => 'ZM',
        ]);

    $response->assertOk();
    $response->assertJson([
        'status' => 'success',
        'message' => 'Payment request initiated successfully',
    ]);

    // Assert that the transaction was created for the correct provider (provider2)
    $this->assertDatabaseHas('transactions', [
        'payment_provider_id' => $provider2->id,
        'provider_transaction_id' => 'LENCO-SUCCESS-REF',
    ]);

    // Assert no transaction was created for provider1
    $this->assertDatabaseMissing('transactions', [
        'payment_provider_id' => $provider1->id,
    ]);
});

test('the switch falls back to the next provider when one fails authentication', function () {
    $user = User::factory()->create(['api_token' => 'switch-auth-fallback']);

    // Provider 1: MTN — its auth (token) call fails, so it returns a 502.
    $mtn = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'MTN MoMo',
        'class' => MtnController::class,
        'config' => ['subscription_key' => 'sk', 'api_user' => 'au', 'api_key' => 'ak', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    // Provider 2: Airtel — authenticates and initiates the collection successfully.
    $airtel = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Airtel Money',
        'class' => AirtelController::class,
        'config' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    Http::fake([
        '*/collection/token/' => Http::response(['error' => 'unauthorized'], 401), // MTN auth fails
        '*/auth/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/merchant/v1/payments/' => Http::response([
            'data' => ['transaction' => ['id' => 'AIRTEL-FALLBACK', 'status' => 'TIP']],
            'status' => ['success' => true],
        ], 200),
    ]);

    $this->withToken('switch-auth-fallback')
        ->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    // The successful transaction belongs to the Airtel fallback, not MTN.
    $this->assertDatabaseHas('transactions', [
        'payment_provider_id' => $airtel->id,
        'provider_transaction_id' => 'AIRTEL-FALLBACK',
    ]);
    $this->assertDatabaseMissing('transactions', ['payment_provider_id' => $mtn->id]);
});

test('the switch falls back to the next provider when one throws during authentication', function () {
    $user = User::factory()->create(['api_token' => 'switch-throw-fallback']);

    // Provider 1: MTN — its token endpoint throws a connection exception.
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'MTN MoMo',
        'class' => MtnController::class,
        'config' => ['subscription_key' => 'sk', 'api_user' => 'au', 'api_key' => 'ak', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    // Provider 2: Airtel — succeeds.
    $airtel = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Airtel Money',
        'class' => AirtelController::class,
        'config' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    Http::fake([
        '*/collection/token/' => fn () => throw new ConnectionException('MTN gateway unreachable'),
        '*/auth/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
        '*/merchant/v1/payments/' => Http::response([
            'data' => ['transaction' => ['id' => 'AIRTEL-AFTER-THROW', 'status' => 'TIP']],
            'status' => ['success' => true],
        ], 200),
    ]);

    $this->withToken('switch-throw-fallback')
        ->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    $this->assertDatabaseHas('transactions', [
        'payment_provider_id' => $airtel->id,
        'provider_transaction_id' => 'AIRTEL-AFTER-THROW',
    ]);
});

test('the switch returns the last failure when every active provider fails', function () {
    $user = User::factory()->create(['api_token' => 'switch-all-fail']);

    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'MTN MoMo',
        'class' => MtnController::class,
        'config' => ['subscription_key' => 'sk', 'api_user' => 'au', 'api_key' => 'ak', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    Http::fake([
        '*/collection/token/' => Http::response(['error' => 'unauthorized'], 401),
    ]);

    $this->withToken('switch-all-fail')
        ->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertStatus(502)
        ->assertJsonPath('status', 'error');
});

test('switch controller returns 400 error if no providers support the country', function () {
    $user = User::factory()->create([
        'api_token' => 'switch-test-token',
    ]);

    // Provider supports MW only, request is for ZM
    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco MW Only',
        'config' => ['api_key' => 'key_mw', 'supported_countries' => ['MW']],
        'class' => LencoController::class,
        'is_active' => true,
    ]);

    $response = $this->withToken('switch-test-token')
        ->postJson('/api/v1/payment/request', [
            'amount' => 50.00,
            'account_number' => '769999999',
            'country' => 'ZM',
        ]);

    $response->assertStatus(400);
    $response->assertJson([
        'status' => 'error',
        'message' => 'No active providers support the requested country',
    ]);
});
