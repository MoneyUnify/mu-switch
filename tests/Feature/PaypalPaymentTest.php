<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\DpoController;
use App\Jobs\SendTransactionCallback;
use App\Models\PaymentProvider;
use App\Models\ProviderLog;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Paypal\PaypalClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

// PayPal calls retry with a pause; don't actually wait in tests.
beforeEach(fn () => Sleep::fake());

function fakePaypal(array $responses = []): void
{
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        ...$responses,
    ]);
}

test('the paypal driver creates an order and returns the checkout link', function () {
    $user = User::factory()->create(['api_token' => 'pp-create']);
    paypalProvider($user);

    fakePaypal(['*/v2/checkout/orders' => Http::response(paypalOrder(), 201)]);

    $response = $this->withToken('pp-create')->postJson('/api/v1/payment/request', [
        'amount' => 25,
        'account_number' => 'payer@example.com',
        'country' => 'ZM',
        'currency' => 'usd',
        'return_url' => 'https://shop.test/thanks',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.reference', 'ORDER-1')
        ->assertJsonPath('data.checkout_url', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1');

    $transactionId = $response->json('data.transaction_id');

    $this->assertDatabaseHas('transactions', [
        'transaction_id' => $transactionId,
        'provider_transaction_id' => 'ORDER-1',
        'currency' => 'USD',
        'status' => TransactionStatus::PENDING->value,
        'return_url' => 'https://shop.test/thanks',
    ]);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/oauth2/token')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('paypal-client-id:paypal-client-secret')));

    Http::assertSent(function (Request $request) use ($transactionId) {
        if (! str_ends_with($request->url(), '/v2/checkout/orders')) {
            return false;
        }

        $paypal = $request['payment_source']['paypal'];

        return $request->hasHeader('PayPal-Request-Id', $transactionId)
            && $request->hasHeader('Authorization', 'Bearer A21-paypal-token')
            && $request['intent'] === 'CAPTURE'
            && $request['purchase_units'][0]['amount'] === ['currency_code' => 'USD', 'value' => '25.00']
            && $request['purchase_units'][0]['custom_id'] === $transactionId
            && $paypal['email_address'] === 'payer@example.com'
            && str_contains($paypal['experience_context']['return_url'], "/checkout/paypal/{$transactionId}/return?signature=")
            && str_contains($paypal['experience_context']['cancel_url'], "/checkout/paypal/{$transactionId}/cancel?signature=");
    });
});

test('the switch only routes to paypal when a paypal currency is requested', function () {
    $user = User::factory()->create(['api_token' => 'pp-routing']);
    paypalProvider($user);

    Http::fake();

    // No currency means Zambian kwacha, which PayPal can't charge.
    $this->withToken('pp-routing')
        ->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'No active providers support ZMW payments in ZM');

    Http::assertNothingSent();
});

test('a fallback never charges the same amount in another currency', function () {
    $user = User::factory()->create(['api_token' => 'pp-fallback']);

    // DPO is tried first for kwacha but can never take a USD payment.
    PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'DPO',
        'class' => DpoController::class,
        'config' => ['company_token' => 't', 'service_type' => '1', 'mno_codes' => ['ZM' => 'AirtelZM'], 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
    paypalProvider($user);

    fakePaypal(['*/v2/checkout/orders' => Http::response(['name' => 'INTERNAL_SERVER_ERROR', 'message' => 'PayPal is down'], 500)]);

    $this->withToken('pp-fallback')
        ->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'USD'])
        ->assertStatus(502)
        ->assertJsonPath('message', 'PayPal is down');

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '3gdirectpay.com'));
});

test('the paypal driver rejects fractional amounts in zero-decimal currencies', function () {
    $user = User::factory()->create(['api_token' => 'pp-jpy']);
    paypalProvider($user);

    Http::fake();

    $this->withToken('pp-jpy')
        ->postJson('/api/v1/payment/request', ['amount' => 10.5, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'JPY'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'JPY amounts must be whole numbers on PayPal');

    Http::assertNothingSent();
});

test('a paypal order error fails the transaction with paypal\'s message', function () {
    $user = User::factory()->create(['api_token' => 'pp-error']);
    paypalProvider($user);

    fakePaypal(['*/v2/checkout/orders' => Http::response([
        'name' => 'UNPROCESSABLE_ENTITY',
        'details' => [['issue' => 'CURRENCY_NOT_SUPPORTED', 'description' => 'Currency code is not supported.']],
    ], 422)]);

    $this->withToken('pp-error')
        ->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'USD'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Currency code is not supported.');

    $this->assertDatabaseHas('transactions', ['currency' => 'USD', 'status' => TransactionStatus::FAILED->value]);
});

test('the switch skips a paypal provider with incomplete credentials', function () {
    $user = User::factory()->create(['api_token' => 'pp-config']);
    paypalProvider($user, ['client_secret' => '']);

    Http::fake();

    $this->withToken('pp-config')
        ->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'USD'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'Client ID, Client Secret and a valid Environment are required for the PayPal provider');

    Http::assertNothingSent();
});

test('the access token is cached between requests', function () {
    $user = User::factory()->create(['api_token' => 'pp-cache']);
    paypalProvider($user);

    fakePaypal(['*/v2/checkout/orders' => Http::sequence()
        ->push(paypalOrder('ORDER-1'), 201)
        ->push(paypalOrder('ORDER-2'), 201)]);

    foreach (range(1, 2) as $attempt) {
        $this->withToken('pp-cache')
            ->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'USD'])
            ->assertOk();
    }

    Http::assertSentCount(3);
});

test('an expired access token is refreshed once and the call retried', function () {
    $user = User::factory()->create(['api_token' => 'pp-refresh']);
    paypalProvider($user);

    Http::fake([
        '*/v1/oauth2/token' => Http::sequence()
            ->push(['access_token' => 'stale-token', 'expires_in' => 32400])
            ->push(['access_token' => 'fresh-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders' => Http::sequence()
            ->push(['name' => 'AUTHENTICATION_FAILURE'], 401)
            ->push(paypalOrder(), 201),
    ]);

    $this->withToken('pp-refresh')
        ->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'USD'])
        ->assertOk()
        ->assertJsonPath('data.reference', 'ORDER-1');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request->hasHeader('Authorization', 'Bearer fresh-token'));
});

test('issued access tokens are redacted from the provider logs', function () {
    $user = User::factory()->create(['api_token' => 'pp-logs']);
    paypalProvider($user);

    fakePaypal(['*/v2/checkout/orders' => Http::response(paypalOrder(), 201)]);

    $this->withToken('pp-logs')
        ->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'USD'])
        ->assertOk();

    $tokenLog = ProviderLog::query()->where('url', 'like', '%/v1/oauth2/token')->sole();

    expect($tokenLog->response_body)->not->toContain('A21-paypal-token')->toContain('[REDACTED]');
});

test('verifying an approved order captures it and notifies the merchant', function () {
    Queue::fake();

    $user = User::factory()->create(['api_token' => 'pp-capture']);
    $transaction = pendingPaypalTransaction(paypalProvider($user), ['callback_url' => 'https://shop.test/callback']);

    fakePaypal([
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
    ]);

    $this->withToken('pp-capture')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', 'COMPLETED');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/ORDER-1/capture')
        && $request->hasHeader('PayPal-Request-Id', 'capture-ORDER-1')
        && $request->body() === '{}');

    Queue::assertPushed(SendTransactionCallback::class);
});

test('an order still awaiting the payer stays pending', function () {
    $user = User::factory()->create(['api_token' => 'pp-pending']);
    $transaction = pendingPaypalTransaction(paypalProvider($user));

    fakePaypal(['*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'PAYER_ACTION_REQUIRED'))]);

    $this->withToken('pp-pending')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('data.provider_status', 'PAYER_ACTION_REQUIRED');

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/capture'));
});

test('an order captured concurrently is read back instead of failing', function () {
    $user = User::factory()->create(['api_token' => 'pp-race']);
    $transaction = pendingPaypalTransaction(paypalProvider($user));

    fakePaypal([
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response([
            'name' => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'ORDER_ALREADY_CAPTURED', 'description' => 'Order already captured.']],
        ], 422),
        '*/v2/checkout/orders/ORDER-1' => Http::sequence()
            ->push(paypalOrder('ORDER-1', 'APPROVED'))
            ->push(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED')),
    ]);

    $this->withToken('pp-race')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertJsonPath('status', 'success');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('a declined capture fails the transaction', function () {
    $user = User::factory()->create(['api_token' => 'pp-declined']);
    $transaction = pendingPaypalTransaction(paypalProvider($user));

    fakePaypal([
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response([
            'name' => 'UNPROCESSABLE_ENTITY',
            'details' => [['issue' => 'INSTRUMENT_DECLINED', 'description' => 'The instrument was declined.']],
        ], 422),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
    ]);

    $this->withToken('pp-declined')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('data.provider_status', 'INSTRUMENT_DECLINED');
});

test('an expired order fails the transaction', function () {
    $user = User::factory()->create(['api_token' => 'pp-expired']);
    $transaction = pendingPaypalTransaction(paypalProvider($user));

    fakePaypal(['*/v2/checkout/orders/ORDER-1' => Http::response(['name' => 'RESOURCE_NOT_FOUND', 'message' => 'Order not found'], 404)]);

    $this->withToken('pp-expired')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});

test('a paypal outage leaves the transaction untouched', function () {
    $user = User::factory()->create(['api_token' => 'pp-outage']);
    $transaction = pendingPaypalTransaction(paypalProvider($user));

    fakePaypal(['*/v2/checkout/orders/ORDER-1' => Http::response(['name' => 'INTERNAL_SERVER_ERROR', 'message' => 'Try later'], 503)]);

    $this->withToken('pp-outage')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertStatus(502)
        ->assertJsonPath('status', 'error');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING);
});

test('a cancelled transaction is never captured later', function () {
    $user = User::factory()->create(['api_token' => 'pp-no-capture']);
    $transaction = pendingPaypalTransaction(paypalProvider($user), ['status' => TransactionStatus::FAILED]);

    fakePaypal(['*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED'))]);

    $this->withToken('pp-no-capture')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertJsonPath('status', 'failed');

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/capture'));
});

test('a settled payment is never downgraded by a missing order', function () {
    $user = User::factory()->create(['api_token' => 'pp-settled']);
    $transaction = pendingPaypalTransaction(paypalProvider($user), ['status' => TransactionStatus::SUCCESS]);

    fakePaypal(['*/v2/checkout/orders/ORDER-1' => Http::response(['name' => 'RESOURCE_NOT_FOUND'], 404)]);

    $this->withToken('pp-settled')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertJsonPath('status', 'success');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('a stalled paypal call is retried instead of failing', function () {
    $user = User::factory()->create(['api_token' => 'pp-stall']);
    $transaction = pendingPaypalTransaction(paypalProvider($user));

    fakePaypal(['*/v2/checkout/orders/ORDER-1' => Http::sequence()
        ->pushFailedConnection('Operation timed out')
        ->push(paypalOrder('ORDER-1', 'PAYER_ACTION_REQUIRED'))]);

    $this->withToken('pp-stall')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'pending');

    Sleep::assertSleptTimes(1);
});

test('a paypal server error is retried with the same idempotency key', function () {
    $user = User::factory()->create(['api_token' => 'pp-5xx']);
    paypalProvider($user);

    fakePaypal(['*/v2/checkout/orders' => Http::sequence()
        ->push(['name' => 'INTERNAL_SERVER_ERROR'], 500)
        ->push(paypalOrder(), 201)]);

    $response = $this->withToken('pp-5xx')
        ->postJson('/api/v1/payment/request', ['amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'USD'])
        ->assertOk()
        ->assertJsonPath('data.reference', 'ORDER-1');

    $requestIds = Http::recorded(fn ($request) => str_ends_with($request->url(), '/v2/checkout/orders'))
        ->map(fn ($pair) => $pair[0]->header('PayPal-Request-Id')[0]);

    // Both tries were sent, with one idempotency key, so PayPal creates one order.
    expect($requestIds->count())->toBe(2)
        ->and($requestIds->unique()->values()->all())->toBe([$response->json('data.transaction_id')]);
});

test('paypal is reported unreachable, with the cause logged, only after every try fails', function () {
    Log::spy();

    $user = User::factory()->create(['api_token' => 'pp-down']);
    $transaction = pendingPaypalTransaction(paypalProvider($user));

    fakePaypal(['*/v2/checkout/orders/ORDER-1' => Http::failedConnection('Connection reset by peer')]);

    $this->withToken('pp-down')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertStatus(502)
        ->assertJsonPath('message', 'PayPal could not be reached');

    Sleep::assertSleptTimes(2);
    Log::shouldHaveReceived('warning')->with('PayPal could not be reached', Mockery::on(
        fn (array $context): bool => str_contains($context['error'], 'Connection reset by peer'),
    ));
    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING);
});

test('paypal tries share the gateway wait budget', function () {
    config(['services.gateways.timeout' => 180]);
    expect(PaypalClient::attemptTimeout())->toBe(60);

    config(['services.gateways.timeout' => 15]);
    expect(PaypalClient::attemptTimeout())->toBe(10);
});

test('gateway calls connect fast and wait up to three minutes by default', function () {
    expect(config('services.gateways.connect_timeout'))->toBe(8)
        ->and(config('services.gateways.timeout'))->toBe(180);
});

test('paypal payment request with zmw currency converts to usd and sets fx fields', function () {
    $user = User::factory()->create(['api_token' => 'pp-fx']);
    paypalProvider($user);

    fakePaypal(['*/v2/checkout/orders' => Http::response(paypalOrder('ORDER-FX', 'PAYER_ACTION_REQUIRED'), 201)]);

    $response = $this->withToken('pp-fx')->postJson('/api/v1/payment/request', [
        'amount' => 500,
        'account_number' => 'payer@example.com',
        'country' => 'ZM',
        'currency' => 'ZMW',
        'allow_fx' => true,
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.is_fx', true)
        ->assertJsonPath('data.original_currency', 'ZMW')
        ->assertJsonPath('data.original_amount', 500)
        ->assertJsonPath('data.currency', 'USD');

    $tx = Transaction::where('provider_transaction_id', 'ORDER-FX')->firstOrFail();
    expect($tx->is_fx)->toBeTrue()
        ->and($tx->fx_rate)->not->toBeNull()
        ->and((float) $tx->fx_rate)->toBeGreaterThan(0)
        ->and($tx->currency)->toBe('USD');
});

test('verifying an fx transaction includes is_fx and fx_rate in the response', function () {
    $user = User::factory()->create(['api_token' => 'pp-fx-verify']);
    $provider = paypalProvider($user);
    $transaction = pendingPaypalTransaction($provider, [
        'is_fx' => true,
        'fx_rate' => '0.03581818',
    ]);

    fakePaypal([
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
    ]);

    $this->withToken('pp-fx-verify')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('data.is_fx', true)
        ->assertJsonPath('data.fx_rate', 0.03581818);
});

test('the payment api can open paypal on its guest card form', function () {
    $user = User::factory()->create(['api_token' => 'pp-guest']);
    paypalProvider($user);

    fakePaypal(['*/v2/checkout/orders' => Http::response(paypalOrder(), 201)]);

    $this->withToken('pp-guest')->postJson('/api/v1/payment/request', [
        'amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'USD', 'pay_with' => 'card',
    ])->assertOk();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['payment_source']['paypal']['experience_context']['landing_page'] === 'GUEST_CHECKOUT');
});
