<?php

use App\Models\PaymentProvider;
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
