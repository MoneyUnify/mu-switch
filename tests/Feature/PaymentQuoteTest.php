<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\LencoController;
use App\Http\Controllers\Providers\LipilaController;
use App\Http\Controllers\Providers\MtnController;
use App\Models\PaymentProvider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Sleep::fake());

function quoteProvider(User $user, string $name, string $class, array $countries = ['ZM']): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => $name,
        'class' => $class,
        'config' => ['api_key' => 'k', 'supported_countries' => $countries],
        'is_active' => true,
    ]);
}

test('a quote shows both sides of the fees for every provider that would route the payment', function () {
    $user = User::factory()->create(['api_token' => 'quote-1']);
    quoteProvider($user, 'Lenco', LencoController::class);
    quoteProvider($user, 'Lipila', LipilaController::class);

    $this->withToken('quote-1')
        ->postJson('/api/v1/payment/quote', ['amount' => 750, 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('data.amount', '750.00')
        ->assertJsonPath('data.currency', 'ZMW')
        ->assertJsonPath('data.quotes.0.provider', 'Lenco')
        ->assertJsonPath('data.quotes.0.estimated', true)
        ->assertJsonPath('data.quotes.0.merchant_pays_fees', [
            'payer_pays' => '750.00',
            'fees' => ['collection' => '7.50', 'settlement' => '8.50', 'total' => '16.00'],
            'merchant_receives' => '734.00',
        ])
        ->assertJsonPath('data.quotes.0.payer_pays_fees.payer_pays', '766.16')
        ->assertJsonPath('data.quotes.0.payer_pays_fees.merchant_receives', '750.00')
        ->assertJsonPath('data.quotes.1.provider', 'Lipila')
        ->assertJsonPath('data.quotes.1.merchant_pays_fees.merchant_receives', '726.25');
});

test('quotes follow the cost-aware routing order', function () {
    $user = User::factory()->create(['api_token' => 'quote-2', 'fee_policy' => 'cost_aware']);
    quoteProvider($user, 'Lipila', LipilaController::class);
    quoteProvider($user, 'Lenco', LencoController::class);

    // Lenco (K16.00) is cheaper than Lipila (K23.75) on K750, so it's tried first.
    $this->withToken('quote-2')
        ->postJson('/api/v1/payment/quote', ['amount' => 750, 'country' => 'ZM'])
        ->assertJsonPath('data.quotes.0.provider', 'Lenco')
        ->assertJsonPath('data.quotes.1.provider', 'Lipila');
});

test('paypal is quoted in the requested currency', function () {
    $user = User::factory()->create(['api_token' => 'quote-3']);
    paypalProvider($user);

    Http::fake();

    $this->withToken('quote-3')
        ->postJson('/api/v1/payment/quote', ['amount' => 100, 'country' => 'ZM', 'currency' => 'usd'])
        ->assertOk()
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.quotes.0.merchant_pays_fees.fees.collection', '3.98')
        ->assertJsonPath('data.quotes.0.merchant_pays_fees.merchant_receives', '96.02')
        ->assertJsonPath('data.quotes.0.payer_pays_fees.payer_pays', '104.12');

    // Quoting never contacts a provider.
    Http::assertNothingSent();
});

test('providers without a fee schedule are quoted with unknown fees', function () {
    $user = User::factory()->create(['api_token' => 'quote-4']);
    quoteProvider($user, 'MTN', MtnController::class);

    $this->withToken('quote-4')
        ->postJson('/api/v1/payment/quote', ['amount' => 100, 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('data.quotes.0.fees_known', false)
        ->assertJsonPath('data.quotes.0.merchant_pays_fees', null)
        ->assertJsonPath('data.quotes.0.payer_pays_fees', null);
});

test('quotes use the same routing errors as payments', function () {
    $user = User::factory()->create(['api_token' => 'quote-5']);
    paypalProvider($user);

    $this->withToken('quote-5')
        ->postJson('/api/v1/payment/quote', ['amount' => 100, 'country' => 'ZM'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'No active providers support ZMW payments in ZM');
});

test('amounts finer than the currency allows are rejected', function () {
    $user = User::factory()->create(['api_token' => 'quote-6']);
    paypalProvider($user);

    $this->withToken('quote-6')
        ->postJson('/api/v1/payment/quote', ['amount' => '10.005', 'country' => 'ZM', 'currency' => 'USD'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'USD amounts can have at most 2 decimal places');
});

test('quotes need an api token', function () {
    $this->postJson('/api/v1/payment/quote', ['amount' => 100, 'country' => 'ZM'])->assertUnauthorized();
});

test('paypal\'s reported fee replaces the estimate once captured', function () {
    $user = User::factory()->create(['api_token' => 'quote-actual']);
    $transaction = pendingPaypalTransaction(paypalProvider($user), ['amount' => 100]);

    expect((float) $transaction->collection_fee)->toBe(3.98)
        ->and($transaction->fee_estimated)->toBeTrue();

    $captured = paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED');
    $captured['purchase_units'][0]['payments']['captures'][0]['seller_receivable_breakdown'] = [
        'gross_amount' => ['currency_code' => 'USD', 'value' => '100.00'],
        'paypal_fee' => ['currency_code' => 'USD', 'value' => '3.70'],
        'net_amount' => ['currency_code' => 'USD', 'value' => '96.30'],
    ];

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response($captured, 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
    ]);

    $this->withToken('quote-actual')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertJsonPath('status', 'success');

    $transaction->refresh();

    expect((float) $transaction->collection_fee)->toBe(3.7)
        ->and((float) $transaction->net_amount)->toBe(96.3)
        ->and($transaction->fee_estimated)->toBeFalse();
});

test('a reported fee in another currency is not mistaken for the actual fee', function () {
    $user = User::factory()->create();
    $transaction = pendingPaypalTransaction(paypalProvider($user), ['amount' => 100]);

    $captured = paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED');
    $captured['purchase_units'][0]['payments']['captures'][0]['seller_receivable_breakdown'] = [
        'paypal_fee' => ['currency_code' => 'EUR', 'value' => '3.40'],
    ];

    $transaction->update(['provider_response' => ['order' => $captured]]);

    expect((float) $transaction->refresh()->collection_fee)->toBe(3.98)
        ->and($transaction->fee_estimated)->toBeTrue();
});

test('the payments page shows each transaction\'s fees and net', function () {
    $user = User::factory()->create();
    pendingPaypalTransaction(paypalProvider($user), ['amount' => 100, 'status' => TransactionStatus::SUCCESS]);

    $this->actingAs($user)
        ->get(route('payments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.data.0.fees', [
                'collection' => '3.98',
                'settlement' => '0.00',
                'total' => '3.98',
                'net' => '96.02',
                'estimated' => true,
            ]));
});

test('provider accounts flag totals that include estimated fees', function () {
    $user = User::factory()->create();
    pendingPaypalTransaction(paypalProvider($user), ['amount' => 100, 'status' => TransactionStatus::SUCCESS]);

    $this->actingAs($user)
        ->get(route('providers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('providers.0.accounts.0.net', 96.02)
            ->where('providers.0.accounts.0.outflow', 3.98)
            ->where('providers.0.accounts.0.estimated', true));
});

test('paypal can be quoted with allow_fx for zmw payments', function () {
    $user = User::factory()->create(['api_token' => 'quote-fx']);
    paypalProvider($user);

    $this->withToken('quote-fx')
        ->postJson('/api/v1/payment/quote', ['amount' => 500, 'country' => 'ZM', 'currency' => 'ZMW', 'allow_fx' => true])
        ->assertOk()
        ->assertJsonPath('data.currency', 'ZMW')
        ->assertJsonPath('data.quotes.0.provider', 'PayPal '.$user->id)
        ->assertJsonPath('data.quotes.0.is_fx', true)
        ->assertJsonPath('data.quotes.0.fx.target_currency', 'USD');
});
