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
            ->has('providerPerformance.data', 0)
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
            ->has('providerPerformance.data', 1)
            ->where('providerPerformance.data.0.successRate', 75)
            ->where('providerPerformance.data.0.volume', 300)
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

test('recent transactions are paginated at 5 per page by default and the size is adjustable', function () {
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

    // Default page size is 5.
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('recentTransactions.data', 5)
            ->where('recentTransactions.total', 13)
            ->where('recentTransactions.last_page', 3)
            ->where('perPageOptions', [5, 10, 25, 50])
        );

    // The size is adjustable via txnPer.
    $this->actingAs($user)
        ->get(route('dashboard', ['txnPer' => 25]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('recentTransactions.data', 13)
            ->where('recentTransactions.per_page', 25)
        );
});

test('provider performance is paginated at 5 per page by default and the size is adjustable', function () {
    $user = User::factory()->create();

    foreach (range(1, 7) as $i) {
        PaymentProvider::create([
            'user_id' => $user->id,
            'name' => "Provider {$i}",
            'class' => 'LencoClass',
            'config' => ['api_key' => 'key'],
            'is_active' => true,
        ]);
    }

    // Default page size is 5.
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providerPerformance.data', 5)
            ->where('providerPerformance.total', 7)
            ->where('providerPerformance.last_page', 2)
        );

    // The size is adjustable via provPer, independently of the transactions table.
    $this->actingAs($user)
        ->get(route('dashboard', ['provPer' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providerPerformance.data', 7)
            ->where('providerPerformance.per_page', 10)
        );
});

test('provider performance can be filtered by search and status', function () {
    $user = User::factory()->create();

    PaymentProvider::create(['user_id' => $user->id, 'name' => 'Airtel Money', 'class' => 'A', 'config' => ['api_key' => 'k'], 'is_active' => true]);
    PaymentProvider::create(['user_id' => $user->id, 'name' => 'MTN MoMo', 'class' => 'B', 'config' => ['api_key' => 'k'], 'is_active' => true]);
    PaymentProvider::create(['user_id' => $user->id, 'name' => 'Lenco', 'class' => 'C', 'config' => ['api_key' => 'k'], 'is_active' => false]);

    // Search by name.
    $this->actingAs($user)
        ->get(route('dashboard', ['pq' => 'mtn']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providerPerformance.data', 1)
            ->where('providerPerformance.data.0.name', 'MTN MoMo')
            ->where('providerFilters.q', 'mtn')
        );

    // Filter by inactive status.
    $this->actingAs($user)
        ->get(route('dashboard', ['pstatus' => 'inactive']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('providerPerformance.data', 1)
            ->where('providerPerformance.data.0.name', 'Lenco')
            ->where('providerFilters.status', 'inactive')
        );

    // Filter by active status.
    $this->actingAs($user)
        ->get(route('dashboard', ['pstatus' => 'active']))
        ->assertInertia(fn (Assert $page) => $page->has('providerPerformance.data', 2));
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
