<?php

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard renders metric props with empty state for new users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('hasProviders', false)
            ->where('stats.transactions.value', 0)
            ->where('stats.volume.value', 0)
            ->where('stats.successRate.value', 0)
            ->where('stats.activeProviders.value', 0)
            ->has('volumeTrend', 14)
            ->has('providerPerformance', 0)
            ->has('recentTransactions.data', 0)
        );
});

test('dashboard aggregates the authenticated users transactions', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'class' => 'LencoClass',
        'config' => ['api_key' => 'key'],
        'is_active' => true,
    ]);

    foreach ([TransactionStatus::SUCCESS, TransactionStatus::SUCCESS, TransactionStatus::SUCCESS, TransactionStatus::FAILED] as $i => $status) {
        Transaction::create([
            'transaction_id' => "txn-{$i}",
            'payment_provider_id' => $provider->id,
            'provider_transaction_id' => "prov-{$i}",
            'customer_id' => $customer->id,
            'amount' => 100,
            'currency' => 'ZMW',
            'status' => $status,
            'direction' => 'credit',
            'is_fx' => false,
        ]);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasProviders', true)
            ->where('currency', 'ZMW')
            ->where('stats.transactions.value', 4)
            ->where('stats.volume.value', 300)
            ->where('stats.successRate.value', 75)
            ->where('stats.failed.value', 1)
            ->where('stats.activeProviders.value', 1)
            ->where('statusBreakdown.success', 3)
            ->where('statusBreakdown.failed', 1)
            ->has('providerPerformance', 1)
            ->where('providerPerformance.0.successRate', 75)
            ->where('providerPerformance.0.volume', 300)
            ->has('recentTransactions.data', 4)
            ->where('recentTransactions.data.0.customer', 'Jane Doe')
        );
});

test('dashboard does not include other users transactions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $customer = Customer::create(['name' => 'Other Customer', 'email' => 'other@example.com']);

    $otherProvider = PaymentProvider::create([
        'user_id' => $otherUser->id,
        'name' => 'Other Provider',
        'class' => 'OtherClass',
        'config' => ['api_key' => 'key'],
        'is_active' => true,
    ]);

    Transaction::create([
        'transaction_id' => 'other-txn',
        'payment_provider_id' => $otherProvider->id,
        'provider_transaction_id' => 'other-prov',
        'customer_id' => $customer->id,
        'amount' => 500,
        'currency' => 'USD',
        'status' => TransactionStatus::SUCCESS,
        'direction' => 'credit',
        'is_fx' => false,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.transactions.value', 0)
            ->where('stats.volume.value', 0)
            ->has('recentTransactions.data', 0)
        );
});

test('recent transactions are paginated at 10 per page', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);
    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'class' => 'LencoClass',
        'config' => ['api_key' => 'key'],
        'is_active' => true,
    ]);

    foreach (range(1, 13) as $i) {
        Transaction::create([
            'transaction_id' => "txn-{$i}",
            'payment_provider_id' => $provider->id,
            'provider_transaction_id' => "prov-{$i}",
            'customer_id' => $customer->id,
            'amount' => 100,
            'currency' => 'ZMW',
            'status' => TransactionStatus::SUCCESS,
            'direction' => 'credit',
            'is_fx' => false,
        ]);
    }

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('recentTransactions.data', 10)
            ->where('recentTransactions.total', 13)
            ->where('recentTransactions.last_page', 2)
        );
});

test('recent transactions can be searched', function () {
    $user = User::factory()->create();
    $alice = Customer::create(['name' => 'Alice Banda', 'email' => 'alice@example.com']);
    $bob = Customer::create(['name' => 'Bob Phiri', 'email' => 'bob@example.com']);
    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'class' => 'LencoClass',
        'config' => ['api_key' => 'key'],
        'is_active' => true,
    ]);

    foreach ([[$alice, 'a'], [$bob, 'b']] as [$customer, $suffix]) {
        Transaction::create([
            'transaction_id' => "txn-{$suffix}",
            'payment_provider_id' => $provider->id,
            'provider_transaction_id' => "prov-{$suffix}",
            'customer_id' => $customer->id,
            'amount' => 100,
            'currency' => 'ZMW',
            'status' => TransactionStatus::SUCCESS,
            'direction' => 'credit',
            'is_fx' => false,
        ]);
    }

    $this->actingAs($user)
        ->get(route('dashboard', ['tx' => 'Alice']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('recentTransactions.data', 1)
            ->where('recentTransactions.data.0.customer', 'Alice Banda')
            ->where('transactionFilters.q', 'Alice')
        );
});
