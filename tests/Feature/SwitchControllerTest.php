<?php

use App\Http\Controllers\Providers\LencoController;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

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
