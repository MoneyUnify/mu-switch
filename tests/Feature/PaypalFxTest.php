<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\Fx\FxQuote;
use App\Support\Fx\FxQuoter;
use App\Support\Paypal\PaypalFxRates;
use App\Support\Paypal\PaypalReceipt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();

    $this->user = User::factory()->create(['api_token' => 'fx-token']);
    $this->paypal = paypalProvider($this->user, ['fx_rates' => 'paypal']);
});

/**
 * PayPal's exchange-rate quotes, from USD unless asked otherwise.
 *
 * @param  array<string, string>  $rates  quote currency => rate
 */
function fakePaypalFx(array $rates = ['ZMW' => '26.80000000', 'KES' => '129.00000000', 'EUR' => '0.91000000'], array $extra = []): void
{
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/pricing/quote-exchange-rates' => fn (Request $request) => Http::response(['exchange_rate_quotes' => collect($request['quote_items'])
            ->filter(fn (array $item): bool => isset($rates[$item['quote_currency']]))
            ->map(fn (array $item): array => [
                'base_amount' => ['currency_code' => $item['base_currency'], 'value' => '1.00'],
                'quote_amount' => ['currency_code' => $item['quote_currency'], 'value' => $rates[$item['quote_currency']]],
                'exchange_rate' => $rates[$item['quote_currency']],
                'fx_id' => 'FX-'.$item['base_currency'].'-'.$item['quote_currency'],
                'expiry_time' => now()->addHours(5)->toIso8601ZuluString(),
                'rate_refresh_time' => now()->addHours(2)->toIso8601ZuluString(),
            ])->values()->all()]),
        ...$extra,
    ]);
}

function fxCalls(): int
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/v2/pricing/quote-exchange-rates'))->count();
}

test('quotes use paypal\'s own rate when the paypal account has fx', function () {
    fakePaypalFx();

    $quote = app(FxQuoter::class)->quote('USD', 'ZMW', 100, ownerId: $this->user->id);

    expect($quote->rateSource)->toBe(FxQuote::SOURCE_PAYPAL)
        ->and((string) $quote->baseRate)->toBe('26.80000000')
        ->and((string) $quote->effectiveRate)->toBe('26.39800000') // PayPal's rate less the 1.5% spread
        ->and((string) $quote->targetAmount)->toBe('2639.80')
        ->and($quote->toArray()['rate_source'])->toBe('paypal');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/pricing/quote-exchange-rates')
        && $request['quote_items'] === [['base_currency' => 'USD', 'quote_currency' => 'ZMW']]);
});

test('paypal\'s rates are cached until paypal refreshes them', function () {
    fakePaypalFx();
    $quoter = app(FxQuoter::class);

    $quoter->quote('USD', 'ZMW', 100, ownerId: $this->user->id);
    $quoter->quoteTarget('USD', 'ZMW', 2500, ownerId: $this->user->id);

    expect(fxCalls())->toBe(1);

    $this->travel(3)->hours();
    $quoter->quote('USD', 'ZMW', 100, ownerId: $this->user->id);

    expect(fxCalls())->toBe(2);
});

test('rates into a currency paypal holds are paypal\'s inverted, and other pairs cross through usd', function () {
    fakePaypalFx();
    $quoter = app(FxQuoter::class);

    $back = $quoter->quote('ZMW', 'USD', 2680, ownerId: $this->user->id);
    $cross = $quoter->quote('KES', 'ZMW', 1000, ownerId: $this->user->id);

    expect($back->rateSource)->toBe('paypal')
        ->and((string) $back->baseRate)->toBe('0.03731343')
        ->and($cross->rateSource)->toBe('paypal')
        ->and((string) $cross->baseRate)->toBe('0.20775194'); // 26.80 / 129.00, both from USD
});

test('without fx on the paypal account, quotes use moneyunify rates and paypal is not asked again for a while', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/pricing/quote-exchange-rates' => Http::response([
            'name' => 'NOT_AUTHORIZED',
            'message' => 'Authorization failed due to insufficient permissions.',
            'details' => [[
                'issue' => 'FEATURE_NOT_PROVISIONED',
                'description' => 'FX-as-a-Service is not provisioned for this account.',
                'links' => [['href' => 'https://sandbox.paypal.com/mep/merchantapps/businesstools/streamlineoperations/fxaashub', 'rel' => 'activate_multi_currency_service']],
            ]],
        ], 403),
    ]);
    $quoter = app(FxQuoter::class);

    $quote = $quoter->quote('USD', 'ZMW', 100, ownerId: $this->user->id);
    $quoter->quote('USD', 'ZMW', 50, ownerId: $this->user->id);

    expect($quote->rateSource)->toBe(FxQuote::SOURCE_MONEYUNIFY)
        ->and((string) $quote->baseRate)->toBe('27.50000000')
        ->and(fxCalls())->toBe(1)
        ->and(app(PaypalFxRates::class)->status($this->paypal))->toMatchArray([
            'state' => 'not_enabled',
            'activation_url' => 'https://sandbox.paypal.com/mep/merchantapps/businesstools/streamlineoperations/fxaashub',
        ]);
});

test('an account set to moneyunify rates never asks paypal', function () {
    Http::fake();
    $this->paypal->update(['config' => [...$this->paypal->config, 'fx_rates' => 'moneyunify']]);

    $quote = app(FxQuoter::class)->quote('USD', 'ZMW', 100, ownerId: $this->user->id);

    expect($quote->rateSource)->toBe('moneyunify');
    Http::assertNothingSent();
});

test('a cross-border transfer locks paypal\'s rate and records where it came from', function () {
    fakePaypalFx(extra: ['*/v2/checkout/orders' => Http::response(paypalOrder(), 201)]);
    PaymentProvider::create(['user_id' => $this->user->id, 'name' => 'Simulated Payouts', 'class' => SandboxPayoutController::class, 'config' => ['supported_countries' => ['ZM']], 'is_active' => true]);

    $this->withToken('fx-token')->postJson('/api/v1/transfer/quote', ['amount' => 2500, 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('data.fx.rate_source', 'paypal')
        ->assertJsonPath('data.fx.base_rate', '26.80000000');

    [$transfer] = app(CrossBorderTransferService::class)->request($this->user, ['amount' => '2500', 'mode' => 'target', 'country' => 'ZM', 'account_number' => '0977123456']);

    expect($transfer->fx_rate_source)->toBe('paypal')
        ->and((float) $transfer->fx_base_rate)->toBe(26.8);
});

test('a payment in another currency carries paypal\'s locked rate into the account\'s settlement currency', function () {
    fakePaypalFx(extra: ['*/v2/checkout/orders' => Http::response(paypalOrder(), 201)]);

    $this->withToken('fx-token')->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'EUR'])->assertOk();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['purchase_units'][0]['payment_instruction'] === ['payee_receivable_fx_rate_id' => 'FX-USD-EUR']);

    $payment = $this->paypal->transactions()->latest('id')->sole();
    expect(data_get($payment->provider_response, 'paypal_fx'))->toMatchArray(['fx_id' => 'FX-USD-EUR', 'charge_currency' => 'EUR', 'settlement_currency' => 'USD']);
});

test('a payment in the settlement currency needs no paypal rate', function () {
    fakePaypalFx(extra: ['*/v2/checkout/orders' => Http::response(paypalOrder(), 201)]);

    $this->withToken('fx-token')->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'USD'])->assertOk();

    expect(fxCalls())->toBe(0);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders') && ! isset($request['purchase_units'][0]['payment_instruction']));
});

test('if paypal suspends the locked rate for a market event, the order goes through without it', function () {
    $attempts = 0;
    fakePaypalFx(extra: ['*/v2/checkout/orders' => function (Request $request) use (&$attempts) {
        $attempts++;

        return isset($request['purchase_units'][0]['payment_instruction'])
            ? Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'FX_RATE_CHANGE_DUE_TO_MARKET_EVENT', 'description' => 'The rate changed.']]], 422)
            : Http::response(paypalOrder(), 201);
    }]);

    $this->withToken('fx-token')->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'EUR'])
        ->assertOk()
        ->assertJsonPath('data.reference', 'ORDER-1');

    expect($attempts)->toBe(2);
});

test('the rates page and api say the rates are paypal\'s', function () {
    fakePaypalFx();

    $this->actingAs($this->user)->get(route('rates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rateSource.source', 'paypal')
            ->where('rateSource.provider', $this->paypal->name)
            ->where('rates.ZMW.rate_source', 'paypal')
            ->where('rates.ZMW.base_rate', 26.8)
            ->where('rates.UGX.rate_source', 'moneyunify'));

    $this->withToken('fx-token')->getJson('/api/v1/fx/rates')
        ->assertOk()
        ->assertJsonPath('data.rate_source', 'paypal')
        ->assertJsonPath('data.paypal_fx.state', 'enabled');
});

test('the rates page shows how to enable paypal fx when it is off on the account', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/pricing/quote-exchange-rates' => Http::response(['name' => 'NOT_AUTHORIZED', 'details' => [['issue' => 'FEATURE_NOT_PROVISIONED', 'links' => [['href' => 'https://sandbox.paypal.com/fxaashub']]]]], 403),
    ]);

    $this->actingAs($this->user)->get(route('rates.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rateSource.source', 'moneyunify')
            ->where('rateSource.state', 'not_enabled')
            ->where('rateSource.activation_url', 'https://sandbox.paypal.com/fxaashub'));
});

test('the receipt shows the rate paypal converted the payment at', function () {
    Http::fake();
    $order = paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED');
    $order['purchase_units'][0]['payments']['captures'][0] += [
        'amount' => ['currency_code' => 'EUR', 'value' => '25.00'],
        'seller_receivable_breakdown' => [
            'exchange_rate' => ['source_currency' => 'EUR', 'target_currency' => 'USD', 'value' => '1.0989'],
            'receivable_amount' => ['currency_code' => 'USD', 'value' => '26.31'],
        ],
    ];
    $payment = pendingPaypalTransaction($this->paypal, ['status' => TransactionStatus::SUCCESS, 'currency' => 'EUR', 'provider_response' => ['order' => $order, 'paypal_fx' => ['fx_id' => 'FX-USD-EUR']]]);

    $receipt = PaypalReceipt::for($payment, refresh: false);

    expect($receipt['conversion'])->toBe(['rate' => '1 EUR = 1.0989 USD', 'locked' => true]);
});

test('checking again forgets what paypal said, so the next quote asks paypal afresh', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/pricing/quote-exchange-rates' => Http::sequence()
            ->push(['name' => 'NOT_AUTHORIZED', 'details' => [['issue' => 'FEATURE_NOT_PROVISIONED']]], 403)
            ->push(['exchange_rate_quotes' => [['quote_amount' => ['currency_code' => 'ZMW', 'value' => '26.80'], 'exchange_rate' => '26.80000000', 'fx_id' => 'FX-1']]]),
    ]);
    $quoter = app(FxQuoter::class);

    expect($quoter->quote('USD', 'ZMW', 100, ownerId: $this->user->id)->rateSource)->toBe('moneyunify');

    // PayPal has since enabled FX on the business account.
    $this->actingAs($this->user)->from(route('rates.index'))->post(route('rates.paypal-fx.check'))->assertRedirect(route('rates.index'));

    expect($quoter->quote('USD', 'ZMW', 100, ownerId: $this->user->id)->rateSource)->toBe('paypal');
});

test('a slow paypal never holds up a quote for long: one quick try, then moneyunify rates', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/pricing/quote-exchange-rates' => Http::failedConnection(),
    ]);
    $quoter = app(FxQuoter::class);

    expect($quoter->quote('USD', 'ZMW', 100, ownerId: $this->user->id)->rateSource)->toBe('moneyunify')
        ->and($quoter->quote('USD', 'ZMW', 50, ownerId: $this->user->id)->rateSource)->toBe('moneyunify')
        ->and(fxCalls())->toBe(1); // no retries, and no second try while PayPal is unavailable

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/pricing/quote-exchange-rates'));
});

test('paypal rates are fetched ahead of time, so quotes are served from the cache', function () {
    fakePaypalFx();

    $this->artisan('paypal-fx:warm')->assertSuccessful();
    expect(fxCalls())->toBe(1);

    $quote = app(FxQuoter::class)->quote('USD', 'ZMW', 100, ownerId: $this->user->id);

    expect($quote->rateSource)->toBe('paypal')
        ->and(fxCalls())->toBe(1);
});
