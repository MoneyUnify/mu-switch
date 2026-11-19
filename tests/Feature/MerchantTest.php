<?php

use App\Enums\CrossBorderStatus;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\CrossBorderTransfer;
use App\Models\Merchant;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\Merchants\PaymentLinks;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();

    $this->aggregator = User::factory()->create(['api_token' => 'aggregator-token']);
    paypalProvider($this->aggregator);
    PaymentProvider::create([
        'user_id' => $this->aggregator->id,
        'name' => 'Simulated Payouts',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
    $this->merchant = Merchant::factory()->for($this->aggregator)->create([
        'name' => 'Kitwe Crafts',
        'wallet_number' => '260977123456',
        'commission_percent' => 2,
    ]);
});

/**
 * PayPal sandbox: new orders, approval, capture and refunds.
 */
function fakeMerchantPaypal(string $captureStatus = 'COMPLETED'): void
{
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', $captureStatus), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
        '*/v2/checkout/orders' => Http::response(paypalOrder(), 201),
        '*/v2/payments/captures/CAPTURE-1/refund' => Http::response(['id' => 'REFUND-1', 'status' => 'COMPLETED'], 201),
    ]);
}

// Onboarding

test('an aggregator onboards a merchant that can be settled', function () {
    $this->actingAs($this->aggregator)
        ->post(route('merchants.store'), [
            'name' => 'Lusaka Bakery',
            'country' => 'ZM',
            'wallet_number' => '0961234567',
            'commission_percent' => '1.5',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $merchant = Merchant::where('name', 'Lusaka Bakery')->sole();

    expect($merchant->currency)->toBe('ZMW')
        ->and($merchant->wallet_number)->toBe('260961234567')
        ->and((string) $merchant->commission_percent)->toBe('1.50')
        ->and($merchant->slug)->toStartWith('lusaka-bakery-');
});

test('a merchant can only be added where a payout provider can settle it', function () {
    $this->actingAs($this->aggregator)
        ->post(route('merchants.store'), ['name' => 'Nairobi Shop', 'country' => 'KE', 'wallet_number' => '0712345678'])
        ->assertSessionHasErrors(['country' => 'None of your payout providers can settle to Kenya. Add one on the Providers page first.']);

    $this->actingAs($this->aggregator)
        ->post(route('merchants.store'), ['name' => 'Greedy', 'country' => 'ZM', 'wallet_number' => '0961234567', 'commission_percent' => 25])
        ->assertSessionHasErrors('commission_percent');
});

test('merchants can be paused, and only by their aggregator', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('merchants.update', $this->merchant->id), ['is_active' => false])
        ->assertNotFound();

    $this->actingAs($this->aggregator)
        ->put(route('merchants.update', $this->merchant->id), ['is_active' => false])
        ->assertSessionHasNoErrors();

    expect($this->merchant->refresh()->is_active)->toBeFalse();
});

// Quoting and paying

test('a quote shows what the customer pays, the commission and what the merchant receives', function () {
    Http::fake();

    $this->withToken('aggregator-token')
        ->postJson("/api/v1/merchants/{$this->merchant->slug}/quote", ['amount' => 2500])
        ->assertOk()
        ->assertJsonPath('data.customer_pays', ['amount' => '92.30', 'currency' => 'USD'])
        ->assertJsonPath('data.merchant_price', ['amount' => '2500.00', 'currency' => 'ZMW'])
        ->assertJsonPath('data.commission.amount', '50.00')
        ->assertJsonPath('data.merchant_receives', ['amount' => '2450.00', 'currency' => 'ZMW'])
        ->assertJsonPath('data.settles_via', 'Simulated Payouts');

    expect(CrossBorderTransfer::count())->toBe(0);
    Http::assertNothingSent();
});

test('a customer pays on the public page and the merchant is settled to its wallet', function () {
    fakeMerchantPaypal();

    $this->get(route('pay.show', $this->merchant))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('pay/show')
            ->where('merchant.name', 'Kitwe Crafts')
            ->where('merchant.currency', 'ZMW')
            ->where('payCurrencies', fn ($currencies) => collect($currencies)->contains('USD')));

    $this->postJson(route('pay.quote', $this->merchant), ['amount' => 2500])
        ->assertOk()
        ->assertJsonPath('data.customer_pays.amount', '92.30');

    $this->post(route('pay.store', $this->merchant), ['amount' => 2500, 'payer_email' => 'buyer@example.com', 'reference' => 'INV-42'])
        ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');

    $transfer = CrossBorderTransfer::sole();
    expect($transfer->merchant_id)->toBe($this->merchant->id)
        ->and($transfer->origin)->toBe('pay_link')
        ->and($transfer->recipient_account)->toBe('260977123456')
        ->and($transfer->target_amount_minor)->toBe(245000)
        ->and($transfer->commission_minor)->toBe(5000)
        ->and($transfer->payer_email)->toBe('buyer@example.com')
        ->and($transfer->narration)->toBe('INV-42')
        ->and($transfer->fundingTransaction->return_url)->toBe(route('pay.receipt', $transfer->reference));

    // PayPal confirms; the scheduler pays the merchant, then confirms delivery.
    $this->artisan('cross-border:refresh');
    $this->artisan('cross-border:refresh');

    expect($transfer->refresh()->status)->toBe(CrossBorderStatus::DELIVERED)
        ->and((float) $transfer->payoutTransaction->amount)->toBe(2450.0);

    $this->get(route('pay.receipt', $transfer->reference))
        ->assertInertia(fn (Assert $page) => $page
            ->component('pay/receipt')
            ->where('receipt.headline', 'Payment complete')
            ->where('receipt.detail', 'Kitwe Crafts has been paid to its mobile-money wallet.')
            ->where('receipt.steps', [
                ['label' => 'Paid with PayPal', 'state' => 'done'],
                ['label' => 'Sending to Kitwe Crafts', 'state' => 'done'],
                ['label' => 'Kitwe Crafts paid', 'state' => 'done'],
            ])
            ->where('receipt.number', strtoupper(substr(str_replace('-', '', $transfer->reference), 0, 10)))
            ->where('receipt.merchant', 'Kitwe Crafts')
            ->where('receipt.price', ['amount' => '2500.00', 'currency' => 'ZMW'])
            ->missing('receipt.recipient'));

    $this->actingAs($this->aggregator)->get(route('merchants.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('impact.payments', 1)
            ->where('impact.paid_merchants', 1)
            ->where('impact.settled.0', ['currency' => 'ZMW', 'settled' => '2450.00', 'commission' => '50.00', 'payments' => 1])
            ->where('impact.average_cost_percent', fn ($cost) => $cost > 3 && $cost < 4)
            ->where('merchants.data.0.stats.settled', '2450.00'));
});

test('if the merchant cannot be settled, the customer is refunded through paypal', function () {
    fakeMerchantPaypal();
    $this->merchant->update(['wallet_number' => '260977120000']); // the simulated driver refuses ...0000

    $this->post(route('pay.store', $this->merchant), ['amount' => 100, 'mode' => 'source']);
    $transfer = CrossBorderTransfer::sole();

    $this->artisan('cross-border:refresh');

    expect($transfer->refresh()->status)->toBe(CrossBorderStatus::REFUNDED);

    $this->get(route('pay.receipt', $transfer->reference))
        ->assertInertia(fn (Assert $page) => $page
            ->where('receipt.headline', 'Refunded to your PayPal account')
            ->where('receipt.steps', [
                ['label' => 'Paid with PayPal', 'state' => 'done'],
                ['label' => 'Sending to Kitwe Crafts', 'state' => 'failed'],
                ['label' => 'Refunded to you', 'state' => 'done'],
            ]));

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/payments/captures/CAPTURE-1/refund'));
});

test('a paused merchant takes no payments', function () {
    Http::fake();
    $this->merchant->update(['is_active' => false]);

    $this->get(route('pay.show', $this->merchant))->assertInertia(fn (Assert $page) => $page->where('merchant.is_active', false));
    $this->postJson(route('pay.quote', $this->merchant), ['amount' => 100])->assertStatus(422)->assertJsonPath('message', 'This merchant is not accepting payments.');
    $this->post(route('pay.store', $this->merchant), ['amount' => 100])->assertSessionHasErrors('amount');

    expect(CrossBorderTransfer::count())->toBe(0);
});

test('merchant payments keep the per-payment cap but not the account\'s daily send limit', function () {
    config(['moneyunify_paypal.limits.per_transaction_max_usd' => 100, 'moneyunify_paypal.limits.daily_max_usd' => 150]);
    // Each payment gets its own PayPal order. This fake comes first, since the
    // first matching fake wins and fakeMerchantPaypal() always returns ORDER-1.
    $count = 0;
    Http::fake(['*/v2/checkout/orders' => function () use (&$count) {
        return Http::response(paypalOrder('ORDER-'.(++$count)), 201);
    }]);
    fakeMerchantPaypal();

    $this->post(route('pay.store', $this->merchant), ['amount' => 120, 'mode' => 'source'])
        ->assertSessionHasErrors(['amount' => 'This transfer is about USD 120.00, over the USD 100 limit per transfer.']);

    foreach ([90, 90, 90] as $amount) {
        $this->post(route('pay.store', $this->merchant), ['amount' => $amount, 'mode' => 'source'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    expect(CrossBorderTransfer::count())->toBe(3)
        ->and(CrossBorderTransfer::where('status', CrossBorderStatus::AWAITING_PAYMENT)->count())->toBe(3);
});

test('receipts exist only for merchant payments', function () {
    $transfer = CrossBorderTransfer::create([
        'reference' => (string) Str::uuid(), 'user_id' => $this->aggregator->id, 'funding_provider_id' => PaymentProvider::first()->id,
        'source_currency' => 'USD', 'source_amount_minor' => 1000, 'target_currency' => 'ZMW', 'target_amount_minor' => 27000,
        'recipient_country' => 'ZM', 'recipient_account' => '260977000001', 'fx_base_rate' => '27.5', 'fx_rate' => '27', 'status' => CrossBorderStatus::DELIVERED,
    ]);

    $this->get(route('pay.receipt', $transfer->reference))->assertNotFound();
});

// The merchant API

test('the merchant api onboards, charges and reports settlements', function () {
    fakeMerchantPaypal();

    $created = $this->withToken('aggregator-token')
        ->postJson('/api/v1/merchants', ['name' => 'Ndola Tailors', 'country' => 'ZM', 'wallet_number' => '0955123456', 'commission_percent' => 1])
        ->assertCreated()
        ->assertJsonPath('data.currency', 'ZMW')
        ->json('data');

    expect($created['pay_url'])->toBe(route('pay.show', $created['slug']));

    $charge = $this->withToken('aggregator-token')->withHeaders(['Idempotency-Key' => 'order-77'])
        ->postJson("/api/v1/merchants/{$created['slug']}/charge", ['amount' => 1000, 'payer_email' => 'buyer@example.com'])
        ->assertOk()
        ->assertJsonPath('status', 'awaiting_payment')
        ->assertJsonPath('data.checkout_url', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1')
        ->assertJsonPath('data.commission.amount', '10.00')
        ->assertJsonPath('data.merchant_receives.amount', '990.00')
        ->json('data');

    expect($charge['receipt_url'])->toBe(route('pay.receipt', $charge['reference']));

    $this->withToken('aggregator-token')->withHeaders(['Idempotency-Key' => 'order-77'])
        ->postJson("/api/v1/merchants/{$created['slug']}/charge", ['amount' => 1000])
        ->assertJsonPath('data.reference', $charge['reference']);

    $this->withToken('aggregator-token')->getJson("/api/v1/merchants/{$created['slug']}/settlements")
        ->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.settlements.0.origin', 'merchant_api');

    $this->withToken('aggregator-token')->getJson('/api/v1/merchants')
        ->assertOk()
        ->assertJsonPath('data.total', 2);
});

test('another aggregator cannot see or charge a merchant', function () {
    Http::fake();
    User::factory()->create(['api_token' => 'other-aggregator']);

    $this->withToken('other-aggregator')->getJson("/api/v1/merchants/{$this->merchant->slug}")->assertNotFound();
    $this->withToken('other-aggregator')->postJson("/api/v1/merchants/{$this->merchant->slug}/charge", ['amount' => 100])->assertNotFound();
});

test('commission is rounded in the merchant\'s favour', function () {
    $this->merchant->update(['commission_percent' => 1.25]);

    ['commission_minor' => $commission, 'net_minor' => $net] = app(CrossBorderTransferService::class)
        ->quoteForMerchant($this->merchant->refresh(), ['amount' => '33.33']);

    // 1.25% of 33.33 is 0.416625: the aggregator keeps 0.41, the merchant 32.92.
    expect($commission)->toBe(41)->and($net)->toBe(3292);
});

test('the pay link can narrow the currencies a customer pays in', function () {
    Http::fake();

    $this->actingAs($this->aggregator)->get(route('merchants.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('payCurrencies', fn ($currencies) => collect($currencies)->contains('USD') && collect($currencies)->contains('EUR'))
            ->where('merchants.data.0.settlement_currencies', ['ZMW']));

    $this->get(route('pay.show', ['merchant' => $this->merchant, 'pay' => 'eur,gbp,XYZ', 'settle' => 'ZMW']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('payCurrencies', ['EUR', 'GBP'])
            ->where('settleCurrency', 'ZMW'));

    // An unknown or unsupported choice falls back to everything allowed.
    $this->get(route('pay.show', ['merchant' => $this->merchant, 'pay' => 'XYZ', 'settle' => 'USD']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('payCurrencies', fn ($currencies) => count($currencies) > 2)
            ->where('settleCurrency', 'ZMW'));

    $this->postJson(route('pay.quote', $this->merchant), ['amount' => 100, 'source_currency' => 'ZMW'])
        ->assertUnprocessable()->assertJsonValidationErrors('source_currency');
    $this->postJson(route('pay.quote', $this->merchant), ['amount' => 100, 'settle_currency' => 'USD'])
        ->assertUnprocessable()->assertJsonValidationErrors('settle_currency');

    $this->postJson(route('pay.quote', $this->merchant), ['amount' => 100, 'mode' => 'source', 'source_currency' => 'EUR'])
        ->assertOk()
        ->assertJsonPath('data.customer_pays', ['amount' => '100.00', 'currency' => 'EUR']);
});

test('the customer sees the merchant\'s name on paypal checkout', function () {
    fakeMerchantPaypal();

    $this->post(route('pay.store', $this->merchant), ['amount' => 100]);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['payment_source']['paypal']['experience_context']['brand_name'] === 'Kitwe Crafts');
});

test('a customer without a paypal account can choose to pay by card through paypal', function () {
    fakeMerchantPaypal();

    $this->post(route('pay.store', $this->merchant), ['amount' => 100, 'pay_with' => 'card']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['payment_source']['paypal']['experience_context']['landing_page'] === 'GUEST_CHECKOUT');
});

test('paying with a paypal account keeps paypal\'s usual checkout', function () {
    fakeMerchantPaypal();

    $this->post(route('pay.store', $this->merchant), ['amount' => 100, 'pay_with' => 'paypal']);
    $this->post(route('pay.store', $this->merchant), ['amount' => 100, 'pay_with' => 'cash'])->assertSessionHasErrors('pay_with');

    Http::assertSentCount(2); // one token and one order
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && ! array_key_exists('landing_page', $request['payment_source']['paypal']['experience_context']));
});

// PayPal JS SDK v6 button

test('the pay page gives paypal\'s js sdk the collecting account\'s public client id', function () {
    $this->get(route('pay.show', $this->merchant))
        ->assertInertia(fn (Assert $page) => $page
            ->where('paypalSdk.client_id', 'paypal-client-id')
            ->where('paypalSdk.environment', 'sandbox')
            ->missing('paypalSdk.client_secret'));
});

test('the sdk button\'s order is made on the server, without redirect links, and captured after approval', function () {
    fakeMerchantPaypal();

    $order = $this->postJson(route('pay.order', $this->merchant), ['amount' => 2500, 'payer_email' => 'buyer@example.com'])
        ->assertCreated()
        ->assertJsonPath('data.order_id', 'ORDER-1')
        ->assertJsonPath('data.client_id', 'paypal-client-id')
        ->assertJsonPath('data.checkout_url', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1')
        ->json('data');

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/v2/checkout/orders')) {
            return false;
        }

        $context = $request['payment_source']['paypal']['experience_context'];

        return ! isset($context['return_url']) && ! isset($context['cancel_url']) && $context['brand_name'] === 'Kitwe Crafts';
    });

    $transfer = CrossBorderTransfer::sole();
    expect($order['capture_url'])->toBe(route('pay.capture', $transfer->reference))
        ->and($transfer->payer_email)->toBe('buyer@example.com');

    // The customer approved in PayPal's window.
    $this->postJson($order['capture_url'])
        ->assertOk()
        ->assertJsonPath('data.receipt_url', route('pay.receipt', $transfer->reference));

    expect($transfer->refresh()->fundingTransaction->status->value)->toBe('success')
        ->and($transfer->status)->not->toBe(CrossBorderStatus::AWAITING_PAYMENT);
});

test('a pay link\'s sdk order keeps the link\'s price', function () {
    fakeMerchantPaypal();
    $link = app(PaymentLinks::class)->create($this->merchant, ['label' => 'Basket', 'amount' => 450, 'settle_currency' => 'ZMW']);

    $this->postJson(route('pay.link.order', $link->code), ['amount' => 1])->assertCreated();

    expect(CrossBorderTransfer::sole()->targetAmount()->isEqualTo(441))->toBeTrue(); // 450 less the 2% commission
});

test('an sdk order the merchant can\'t take is explained, not thrown', function () {
    Http::fake();
    $this->merchant->update(['is_active' => false]);

    $this->postJson(route('pay.order', $this->merchant), ['amount' => 2500])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This merchant is not accepting payments.');
});

test('only merchant payments can be captured from the pay page', function () {
    $user = User::factory()->create();
    paypalProvider($user);
    PaymentProvider::create(['user_id' => $user->id, 'name' => 'Simulated Payouts 2', 'class' => SandboxPayoutController::class, 'config' => ['supported_countries' => ['ZM']], 'is_active' => true]);
    fakeMerchantPaypal();
    [$transfer] = app(CrossBorderTransferService::class)->request($user, ['amount' => '2500', 'mode' => 'target', 'country' => 'ZM', 'account_number' => '0977123456']);

    $this->postJson(route('pay.capture', $transfer->reference))->assertNotFound();
});

test('while the merchant is being paid, the receipt says so step by step', function () {
    fakeMerchantPaypal();
    [$transfer] = app(CrossBorderTransferService::class)->collectForMerchant($this->merchant, ['amount' => '100']);
    $transfer->update(['status' => CrossBorderStatus::PAYING_OUT]);

    $this->get(route('pay.receipt', $transfer->reference))
        ->assertInertia(fn (Assert $page) => $page
            ->where('receipt.is_final', false)
            ->where('receipt.headline', 'Payment received')
            ->where('receipt.detail', "PayPal has your payment. Kitwe Crafts is being paid to its mobile-money wallet, and this page updates as soon as it's done.")
            ->where('receipt.steps.1', ['label' => 'Sending to Kitwe Crafts', 'state' => 'current'])
            ->where('receipt.steps.2', ['label' => 'Kitwe Crafts paid', 'state' => 'upcoming']));
});
