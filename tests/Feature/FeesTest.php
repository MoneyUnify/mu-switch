<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\FlutterwaveController;
use App\Http\Controllers\Providers\LencoController;
use App\Http\Controllers\Providers\LipilaController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function feeCustomer(): Customer
{
    return Customer::create(['name' => 'Fee Payer', 'email' => 'fee@example.com']);
}

function feeTransaction(PaymentProvider $provider, array $attrs = []): Transaction
{
    static $seq = 0;
    $seq++;

    return Transaction::create([
        'transaction_id' => 'fee-'.$seq,
        'payment_provider_id' => $provider->id,
        'provider_transaction_id' => 'p-'.$seq,
        'customer_id' => feeCustomer()->id,
        'amount' => 750,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::SUCCESS,
        'direction' => 'credit',
        'is_fx' => false,
        ...$attrs,
    ]);
}

test('the fee breakdown is estimated from the provider schedule (Lenco 1% + K8.50)', function () {
    $user = User::factory()->create();
    $provider = PaymentProvider::create(['user_id' => $user->id, 'name' => 'Lenco', 'class' => LencoController::class, 'config' => ['api_key' => 'k'], 'is_active' => true]);

    $tx = feeTransaction($provider, ['amount' => 750]);

    expect((float) $tx->collection_fee)->toBe(7.5)
        ->and((float) $tx->settlement_fee)->toBe(8.5)
        ->and((float) $tx->net_amount)->toBe(734.0)
        ->and($tx->fee_estimated)->toBeTrue();
});

test('Lipila fees are 2.5% + K5', function () {
    $user = User::factory()->create();
    $provider = PaymentProvider::create(['user_id' => $user->id, 'name' => 'Lipila', 'class' => LipilaController::class, 'config' => ['api_key' => 'k'], 'is_active' => true]);

    $tx = feeTransaction($provider, ['amount' => 750]);

    expect((float) $tx->collection_fee)->toBe(18.75)
        ->and((float) $tx->settlement_fee)->toBe(5.0)
        ->and((float) $tx->net_amount)->toBe(726.25);
});

test('the actual collection fee returned by a provider is captured (not estimated)', function () {
    $user = User::factory()->create();
    $provider = PaymentProvider::create(['user_id' => $user->id, 'name' => 'Flutterwave', 'class' => FlutterwaveController::class, 'config' => ['secret_key' => 'FLWSECK'], 'is_active' => true]);

    // Flutterwave returns its fee in data.app_fee.
    $tx = feeTransaction($provider, ['amount' => 1500, 'currency' => 'KES', 'provider_response' => ['data' => ['app_fee' => 21.0]]]);

    expect((float) $tx->collection_fee)->toBe(21.0)
        ->and((float) $tx->net_amount)->toBe(1479.0)
        ->and($tx->fee_estimated)->toBeFalse();
});

test('a provider without a schedule leaves the net equal to the gross', function () {
    $user = User::factory()->create();
    $provider = PaymentProvider::create(['user_id' => $user->id, 'name' => 'Mystery', 'class' => 'App\\Nope', 'config' => [], 'is_active' => true]);

    $tx = feeTransaction($provider, ['amount' => 500]);

    expect($tx->collection_fee)->toBeNull()
        ->and((float) $tx->net_amount)->toBe(500.0);
});

test('cost-aware routing tries the cheapest provider first', function () {
    $user = User::factory()->create(['api_token' => 'cost-token']);
    $user->forceFill(['fee_policy' => 'cost_aware'])->save();

    // Two Lipila providers hitting the same endpoint but priced differently via
    // a per-provider fee_schedule override (created expensive-first).
    $expensive = PaymentProvider::create([
        'user_id' => $user->id, 'name' => 'Pricey', 'class' => LipilaController::class, 'is_active' => true,
        'config' => ['api_key' => 'k1', 'supported_countries' => ['ZM'], 'fee_schedule' => ['collection' => ['percent' => 5.0], 'settlement' => ['default' => 5.0]]],
    ]);
    $cheap = PaymentProvider::create([
        'user_id' => $user->id, 'name' => 'Cheap', 'class' => LipilaController::class, 'is_active' => true,
        'config' => ['api_key' => 'k2', 'supported_countries' => ['ZM'], 'fee_schedule' => ['collection' => ['percent' => 1.0], 'settlement' => ['default' => 5.0]]],
    ]);

    Http::fake(['*blz.lipila.io*' => Http::response(['status' => 'Pending', 'identifier' => 'LIP-1'], 200)]);

    $this->withToken('cost-token')
        ->postJson('/api/v1/payment/request', ['amount' => 750, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk();

    // The cheaper provider handled it despite being created second.
    $this->assertDatabaseHas('transactions', ['payment_provider_id' => $cheap->id]);
    $this->assertDatabaseMissing('transactions', ['payment_provider_id' => $expensive->id]);
});

test('the default transparent policy keeps the original provider order', function () {
    $user = User::factory()->create(['api_token' => 'transparent-token']);

    $first = PaymentProvider::create([
        'user_id' => $user->id, 'name' => 'Pricey', 'class' => LipilaController::class, 'is_active' => true,
        'config' => ['api_key' => 'k1', 'supported_countries' => ['ZM'], 'fee_schedule' => ['collection' => ['percent' => 5.0], 'settlement' => ['default' => 5.0]]],
    ]);
    PaymentProvider::create([
        'user_id' => $user->id, 'name' => 'Cheap', 'class' => LipilaController::class, 'is_active' => true,
        'config' => ['api_key' => 'k2', 'supported_countries' => ['ZM'], 'fee_schedule' => ['collection' => ['percent' => 1.0], 'settlement' => ['default' => 5.0]]],
    ]);

    Http::fake(['*blz.lipila.io*' => Http::response(['status' => 'Pending', 'identifier' => 'LIP-1'], 200)]);

    $this->withToken('transparent-token')
        ->postJson('/api/v1/payment/request', ['amount' => 750, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk();

    // Default order (by id) → the first-created provider handles it.
    $this->assertDatabaseHas('transactions', ['payment_provider_id' => $first->id]);
});
