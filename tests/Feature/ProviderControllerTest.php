<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\LencoController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guest cannot access providers page', function () {
    $this->get(route('providers.index'))->assertRedirect(route('login'));
});

test('user can view their own providers', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $provider1 = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'User Provider',
        'class' => 'SomeClass',
        'config' => ['api_key' => 'key1'],
        'is_active' => true,
    ]);

    $provider2 = PaymentProvider::create([
        'user_id' => $otherUser->id,
        'name' => 'Other User Provider',
        'class' => 'OtherClass',
        'config' => ['api_key' => 'key2'],
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('providers.index'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('providers/index')
            ->has('providers', 1)
            ->where('providers.0.name', 'User Provider')
            // Drivers are auto-discovered; both shipped drivers are exposed with their config fields.
            ->where('availableDrivers', fn ($drivers) => collect($drivers)->pluck('name')->contains('Lenco')
                && collect($drivers)->pluck('name')->contains('Airtel Money')
                && collect($drivers)->firstWhere('name', 'Lenco')['config_fields'][0]['key'] === 'api_key'
                && collect($drivers)->firstWhere('name', 'Airtel Money')['config_fields'][0]['key'] === 'client_id'
                && collect($drivers)->firstWhere('name', 'Lenco')['default_logo'] !== null)
        );
});

test('user can create a payment provider', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('providers.store'), [
            'name' => 'New Provider',
            'class' => 'App\\Http\\Controllers\\Providers\\LencoController',
            'config' => ['api_key' => 'secret_key'],
            'supported_countries' => ['ZM', 'MW'],
            'is_active' => 1,
            'logo_url' => 'https://example.com/logo.png',
        ]);

    $response->assertRedirect(route('providers.index'));

    $this->assertDatabaseHas('payment_providers', [
        'user_id' => $user->id,
        'name' => 'New Provider',
        'class' => 'App\\Http\\Controllers\\Providers\\LencoController',
        'is_active' => 1,
        'logo_url' => 'https://example.com/logo.png',
    ]);

    $provider = PaymentProvider::where('name', 'New Provider')->first();
    expect($provider->config)->toBe([
        'api_key' => 'secret_key',
        'supported_countries' => ['ZM', 'MW'],
    ]);
});

test('providers expose a per-currency account summary of successful transactions', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-acct@example.com']);
    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'class' => LencoController::class,
        'config' => ['api_key' => 'k'],
        'is_active' => true,
    ]);

    $make = fn (float $amount, string $currency, TransactionStatus $status) => Transaction::create([
        'transaction_id' => uniqid('t-'),
        'payment_provider_id' => $provider->id,
        'provider_transaction_id' => uniqid('p-'),
        'customer_id' => $customer->id,
        'amount' => $amount,
        'currency' => $currency,
        'country' => 'ZM',
        'status' => $status,
        'direction' => 'credit',
        'is_fx' => false,
    ]);

    // Two successful ZMW (K750 each: 1% + K8.50 fees → net 734), one successful USD, one failed (ignored).
    $make(750, 'ZMW', TransactionStatus::SUCCESS);
    $make(750, 'ZMW', TransactionStatus::SUCCESS);
    $make(100, 'USD', TransactionStatus::SUCCESS);
    $make(500, 'ZMW', TransactionStatus::FAILED);

    $this->actingAs($user)
        ->get(route('providers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('providers.0.accounts', 2)
            // Ordered by inflow desc → ZMW (1500) first.
            ->where('providers.0.accounts.0.currency', 'ZMW')
            ->where('providers.0.accounts.0.inflow', 1500)
            ->where('providers.0.accounts.0.outflow', 32) // (7.5 + 8.5) * 2
            ->where('providers.0.accounts.0.net', 1468) // 734 * 2
            ->where('providers.0.accounts.1.currency', 'USD')
        );
});

test('creating a provider with a per-market field stores a value for each ticked market', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->post(route('providers.store'), [
            'name' => 'Ting Multi',
            'class' => 'App\\Http\\Controllers\\Providers\\TingController',
            'config' => ['api_key' => 'k', 'client_id' => 'c', 'client_secret' => 's', 'service_code' => 'MU'],
            'supported_countries' => ['KE', 'TZ'],
            'market_values' => ['KE' => 'SAFKE', 'TZ' => 'VODACOMTZ'],
            'is_active' => 1,
        ]);

    $response->assertRedirect(route('providers.index'));

    $provider = PaymentProvider::where('name', 'Ting Multi')->first();
    expect($provider->config['payment_option_codes'])->toBe(['KE' => 'SAFKE', 'TZ' => 'VODACOMTZ']);
});

test('creating a per-market provider fails when a ticked market has no value', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('providers.store'), [
            'name' => 'Ting Missing',
            'class' => 'App\\Http\\Controllers\\Providers\\TingController',
            'config' => ['api_key' => 'k', 'client_id' => 'c', 'client_secret' => 's', 'service_code' => 'MU'],
            'supported_countries' => ['KE', 'TZ'],
            'market_values' => ['KE' => 'SAFKE'], // TZ missing
            'is_active' => 1,
        ])
        ->assertSessionHasErrors('market_values.TZ');

    $this->assertDatabaseMissing('payment_providers', ['name' => 'Ting Missing']);
});

test('user can update their own payment provider', function () {
    $user = User::factory()->create();
    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Old Name',
        'class' => 'OldClass',
        'config' => ['api_key' => 'old_key', 'supported_countries' => ['ZM']],
        'is_active' => false,
    ]);

    $response = $this->actingAs($user)
        ->put(route('providers.update', $provider), [
            'name' => 'Updated Name',
            'class' => 'NewClass',
            'config' => ['api_key' => 'new_key'],
            'supported_countries' => ['ZM', 'MW'],
            'is_active' => 1,
        ]);

    $response->assertRedirect(route('providers.index'));

    $provider->refresh();
    expect($provider->name)->toBe('Updated Name');
    expect($provider->class)->toBe('NewClass');
    expect($provider->is_active)->toBe(true);
    expect($provider->config)->toBe([
        'api_key' => 'new_key',
        'supported_countries' => ['ZM', 'MW'],
    ]);
});

test('user cannot update other users payment provider', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $provider = PaymentProvider::create([
        'user_id' => $otherUser->id,
        'name' => 'Other Provider',
        'class' => 'OtherClass',
        'config' => ['api_key' => 'other_key'],
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->put(route('providers.update', $provider), [
            'name' => 'Hacked Provider',
            'class' => 'HackedClass',
            'api_key' => 'hacked_key',
            'supported_countries' => 'ZM',
            'is_active' => 0,
        ])
        ->assertForbidden();
});

test('user can delete their own payment provider', function () {
    $user = User::factory()->create();
    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'To Delete',
        'class' => 'DeleteClass',
        'config' => ['api_key' => 'delete_key'],
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)
        ->delete(route('providers.destroy', $provider));

    $response->assertRedirect(route('providers.index'));

    $this->assertDatabaseMissing('payment_providers', [
        'id' => $provider->id,
    ]);
});

test('user cannot delete other users payment provider', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $provider = PaymentProvider::create([
        'user_id' => $otherUser->id,
        'name' => 'Other Provider',
        'class' => 'OtherClass',
        'config' => ['api_key' => 'other_key'],
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->delete(route('providers.destroy', $provider))
        ->assertForbidden();

    $this->assertDatabaseHas('payment_providers', [
        'id' => $provider->id,
    ]);
});
