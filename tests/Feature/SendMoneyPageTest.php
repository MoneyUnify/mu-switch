<?php

use App\Enums\CrossBorderStatus;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\CrossBorderTransfer;
use App\Models\PaymentProvider;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    $this->user = User::factory()->create();
});

function sendMoneyProviders(User $user): void
{
    paypalProvider($user);
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Simulated Payouts',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
}

function fakeSendMoneyPaypal(): void
{
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
        '*/v2/checkout/orders' => Http::response(paypalOrder(), 201),
    ]);
}

function sendMoney(array $body = [])
{
    return test()->post(route('send.store'), [
        'amount' => 2500,
        'mode' => 'target',
        'country' => 'ZM',
        'source_currency' => 'USD',
        'account_number' => '0977123456',
        'recipient_name' => 'Mum',
        ...$body,
    ]);
}

test('the send money page lists where the account can send and in what currencies', function () {
    sendMoneyProviders($this->user);

    $this->actingAs($this->user)->get(route('send.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('send/index')
            ->where('countries', [['code' => 'ZM', 'name' => 'Zambia', 'currency' => 'ZMW']])
            ->where('sourceCurrencies', fn ($currencies) => collect($currencies)->contains('USD') && ! collect($currencies)->contains('ZMW'))
            ->where('transfers.data', [])
            ->where('perPageOptions', [10, 25, 50]));
});

test('without paypal or a payout provider the page has nothing to offer', function () {
    $this->actingAs($this->user)->get(route('send.index'))
        ->assertInertia(fn (Assert $page) => $page->where('countries', [])->where('sourceCurrencies', []));
});

test('the form quotes live without creating anything', function () {
    sendMoneyProviders($this->user);
    Http::fake();

    $this->actingAs($this->user)
        ->postJson(route('send.quote'), ['amount' => 2500, 'country' => 'ZM', 'mode' => 'target'])
        ->assertOk()
        ->assertJsonPath('data.sender_pays.amount', '92.30')
        ->assertJsonPath('data.recipient_gets.amount', '2500.00');

    $this->actingAs($this->user)
        ->postJson(route('send.quote'), ['amount' => 2500, 'country' => 'KE', 'mode' => 'target'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'No active payout provider can pay KES in Kenya.');

    expect(CrossBorderTransfer::count())->toBe(0);
});

test('paying sends the browser to paypal and brings the sender back to the page', function () {
    sendMoneyProviders($this->user);
    fakeSendMoneyPaypal();
    $this->actingAs($this->user);

    sendMoney()->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');

    $transfer = CrossBorderTransfer::sole();
    expect($transfer->status)->toBe(CrossBorderStatus::AWAITING_PAYMENT)
        ->and($transfer->recipient_name)->toBe('Mum')
        ->and($transfer->fundingTransaction->return_url)->toBe(route('send.index'));
});

test('an inertia visit gets a full-page redirect to paypal', function () {
    sendMoneyProviders($this->user);
    fakeSendMoneyPaypal();

    $this->actingAs($this->user)->withHeaders(['X-Inertia' => 'true'])
        ->post(route('send.store'), ['amount' => 50, 'country' => 'ZM', 'account_number' => '0977123456', 'mode' => 'source'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');
});

test('a transfer that cannot start is reported, not thrown', function () {
    sendMoneyProviders($this->user);
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders' => Http::response(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'PAYEE_ACCOUNT_RESTRICTED']]], 422),
    ]);

    $this->actingAs($this->user)->from(route('send.index'));
    sendMoney()
        ->assertRedirect(route('send.index'))
        ->assertInertiaFlash('toast.type', 'error');

    expect(CrossBorderTransfer::sole()->status)->toBe(CrossBorderStatus::PAYMENT_FAILED);
});

test('coming back from paypal highlights the transfer, which then moves on its own', function () {
    sendMoneyProviders($this->user);
    fakeSendMoneyPaypal();
    $this->actingAs($this->user);
    sendMoney();

    $transfer = CrossBorderTransfer::sole();
    $funding = $transfer->fundingTransaction;

    $this->get(route('send.index', ['transaction_id' => $funding->transaction_id, 'status' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('returned', $transfer->reference)
            ->where('transfers.data.0.status', 'awaiting_payment'));

    // The scheduler captures the approved payment, pays out, then confirms delivery.
    $this->artisan('cross-border:refresh')->assertSuccessful();
    expect($transfer->refresh()->status)->toBe(CrossBorderStatus::PAYING_OUT);

    $this->artisan('cross-border:refresh')->assertSuccessful();
    expect($transfer->refresh()->status)->toBe(CrossBorderStatus::DELIVERED);

    $this->get(route('send.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transfers.data.0.status', 'delivered')
            ->where('transfers.data.0.is_final', true)
            ->where('transfers.data.0.timeline.3.status', 'delivered'));
});

test('a transfer can be checked now, but only by its owner', function () {
    sendMoneyProviders($this->user);
    fakeSendMoneyPaypal();
    $this->actingAs($this->user);
    sendMoney();
    $transfer = CrossBorderTransfer::sole();

    $this->actingAs(User::factory()->create())
        ->post(route('send.verify', $transfer->reference))
        ->assertForbidden();

    $this->actingAs($this->user)->from(route('send.index'))
        ->post(route('send.verify', $transfer->reference))
        ->assertRedirect(route('send.index'))
        ->assertInertiaFlash('toast.message', 'Sending to recipient.');
});

test('guests cannot open the send money page', function () {
    $this->get(route('send.index'))->assertRedirect(route('login'));
});

test('a sender without a paypal account can pay by card through paypal', function () {
    sendMoneyProviders($this->user);
    fakeSendMoneyPaypal();
    $this->actingAs($this->user);

    sendMoney(['pay_with' => 'card'])->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['payment_source']['paypal']['experience_context']['landing_page'] === 'GUEST_CHECKOUT');
});
