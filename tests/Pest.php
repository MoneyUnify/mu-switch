<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\PaypalController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A PayPal provider for the given user, in the sandbox environment.
 *
 * @param  array<string, mixed>  $config
 */
function paypalProvider(User $user, array $config = []): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'PayPal '.$user->id,
        'class' => PaypalController::class,
        'config' => [
            'client_id' => 'paypal-client-id',
            'client_secret' => 'paypal-client-secret',
            'environment' => 'sandbox',
            'webhook_id' => 'WH-123',
            'supported_countries' => ['ZM', 'KE'],
            // Tests opt in to PayPal FX explicitly (see PaypalFxTest).
            'fx_rates' => 'moneyunify',
            ...$config,
        ],
        'is_active' => true,
    ]);
}

/**
 * A PayPal Orders v2 response body.
 *
 * @return array<string, mixed>
 */
function paypalOrder(string $id = 'ORDER-1', string $status = 'PAYER_ACTION_REQUIRED', ?string $captureStatus = null): array
{
    $unit = ['reference_id' => 'ref', 'amount' => ['currency_code' => 'USD', 'value' => '25.00']];

    if ($captureStatus) {
        $unit['payments'] = ['captures' => [['id' => 'CAPTURE-1', 'status' => $captureStatus]]];
    }

    return [
        'id' => $id,
        'status' => $status,
        'purchase_units' => [$unit],
        'links' => [
            ['rel' => 'self', 'href' => "https://api-m.sandbox.paypal.com/v2/checkout/orders/{$id}"],
            ['rel' => 'payer-action', 'href' => "https://www.sandbox.paypal.com/checkoutnow?token={$id}"],
        ],
    ];
}

/**
 * A pending PayPal transaction for the given provider.
 *
 * @param  array<string, mixed>  $attributes
 */
function pendingPaypalTransaction(PaymentProvider $provider, array $attributes = []): Transaction
{
    $customer = Customer::firstOrCreate(['email' => 'payer@example.com'], ['name' => 'Payer']);

    return Transaction::create([
        'transaction_id' => (string) Str::uuid(),
        'payment_provider_id' => $provider->id,
        'provider_transaction_id' => 'ORDER-1',
        'customer_id' => $customer->id,
        'amount' => 25,
        'currency' => 'USD',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        ...$attributes,
    ]);
}
