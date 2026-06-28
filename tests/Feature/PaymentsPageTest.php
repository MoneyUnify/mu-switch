<?php

use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function paymentsProvider(User $user): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco '.$user->id,
        'class' => 'LencoClass',
        'config' => ['api_key' => 'key'],
        'is_active' => true,
    ]);
}

function makeTransaction(PaymentProvider $provider, Customer $customer, array $attrs = []): Transaction
{
    static $seq = 0;
    $seq++;

    return Transaction::create([
        'transaction_id' => 'txn-'.$seq,
        'payment_provider_id' => $provider->id,
        'provider_transaction_id' => 'prov-'.$seq,
        'customer_id' => $customer->id,
        'amount' => 100,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::SUCCESS,
        'direction' => 'credit',
        'is_fx' => false,
        ...$attrs,
    ]);
}

test('guests cannot view the payments page', function () {
    $this->get(route('payments.index'))->assertRedirect(route('login'));
});

test('the payments page renders an empty state for new users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('payments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payments/index')
            ->where('metrics.total', 0)
            ->where('metrics.successRate', null)
            ->has('metricTrend.total', 14)
            ->has('metricTrend.success', 14)
            ->has('metricTrend.failed', 14)
            ->has('topCurrencies', 0)
            ->has('topCountries', 0)
            ->has('transactions.data', 0)
        );
});

test('the payments page aggregates metrics, currencies and countries scoped to the user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-pay@example.com']);
    $provider = paymentsProvider($user);

    makeTransaction($provider, $customer, ['currency' => 'ZMW', 'country' => 'ZM', 'status' => TransactionStatus::SUCCESS, 'amount' => 100]);
    makeTransaction($provider, $customer, ['currency' => 'ZMW', 'country' => 'ZM', 'status' => TransactionStatus::SUCCESS, 'amount' => 50]);
    makeTransaction($provider, $customer, ['currency' => 'ZMW', 'country' => 'ZM', 'status' => TransactionStatus::FAILED, 'amount' => 25]);
    makeTransaction($provider, $customer, ['currency' => 'KES', 'country' => 'KE', 'status' => TransactionStatus::PENDING, 'amount' => 75]);

    // Another account's transaction must never be counted.
    $otherProvider = paymentsProvider($other);
    makeTransaction($otherProvider, $customer, ['currency' => 'NGN', 'country' => 'NG']);

    $this->actingAs($user)
        ->get(route('payments.index', ['range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.total', 4)
            ->where('metrics.success', 2)
            ->where('metrics.failed', 1)
            ->where('metrics.pending', 1)
            ->where('metrics.volume', 150)
            ->where('metrics.successRate', 50)
            ->where('topCurrencies.0.currency', 'ZMW')
            ->where('topCurrencies.0.count', 3)
            ->where('topCountries.0.code', 'ZM')
            ->where('topCountries.0.name', 'Zambia')
            ->has('transactions.data', 4)
        );
});

test('the transactions table exposes the payer account and customer name', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Alice Banda', 'email' => 'alice@example.com']);
    CustomerAccount::create(['customer_id' => $customer->id, 'name' => 'Alice Banda', 'number' => '260977123456', 'country' => 'ZM']);
    $provider = paymentsProvider($user);

    makeTransaction($provider, $customer, ['country' => 'ZM']);

    $this->actingAs($user)
        ->get(route('payments.index', ['range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.data.0.account', '260977123456')
            ->where('transactions.data.0.customerName', 'Alice Banda')
        );
});

test('the payments table can be filtered by status', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-pay2@example.com']);
    $provider = paymentsProvider($user);

    makeTransaction($provider, $customer, ['status' => TransactionStatus::SUCCESS]);
    makeTransaction($provider, $customer, ['status' => TransactionStatus::FAILED]);
    makeTransaction($provider, $customer, ['status' => TransactionStatus::FAILED]);

    $this->actingAs($user)
        ->get(route('payments.index', ['status' => 'failed', 'range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'failed')
            ->has('transactions.data', 2)
            // Metrics still reflect the whole window, not just the filtered status.
            ->where('metrics.total', 3)
        );
});
