<?php

use App\Enums\CrossBorderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\DpoController;
use App\Jobs\ProcessPaypalWebhookEvent;
use App\Models\CrossBorderTransfer;
use App\Models\PaymentProvider;
use App\Models\ProviderWebhookEvent;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

// PayPal calls retry with a pause; don't actually wait in tests.
beforeEach(fn () => Sleep::fake());

/**
 * A PAYMENT.CAPTURE.COMPLETED event for ORDER-1.
 *
 * @return array<string, mixed>
 */
function captureCompletedEvent(string $id = 'WH-EVENT-1'): array
{
    return [
        'id' => $id,
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource_type' => 'capture',
        'resource' => [
            'id' => 'CAPTURE-1',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-1']],
        ],
    ];
}

/**
 * The transmission headers PayPal signs each delivery with.
 *
 * @return array<string, string>
 */
function paypalWebhookHeaders(): array
{
    return [
        'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
        'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
        'PAYPAL-TRANSMISSION-ID' => 'TRANSMISSION-1',
        'PAYPAL-TRANSMISSION-SIG' => 'signature==',
        'PAYPAL-TRANSMISSION-TIME' => '2026-10-15T10:00:00Z',
    ];
}

function fakePaypalWebhook(string $verification = 'SUCCESS', ?array $order = null): void
{
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => $verification]),
        '*/v2/checkout/orders/ORDER-1' => Http::response($order ?? paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED')),
    ]);
}

test('a verified capture event settles the transaction', function () {
    $provider = paypalProvider(User::factory()->create());
    $transaction = pendingPaypalTransaction($provider);

    fakePaypalWebhook();

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertOk()
        ->assertJsonPath('message', 'Event received');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS)
        ->and(ProviderWebhookEvent::sole()->processed_at)->not->toBeNull();

    // The raw event is forwarded untouched for signature verification.
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/verify-webhook-signature')
        && $request['webhook_id'] === 'WH-123'
        && $request['transmission_id'] === 'TRANSMISSION-1'
        && $request['webhook_event'] === captureCompletedEvent());
});

test('a redelivered event is acknowledged without reprocessing', function () {
    $provider = paypalProvider(User::factory()->create());
    pendingPaypalTransaction($provider);

    fakePaypalWebhook();

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())->assertOk();
    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertOk()
        ->assertJsonPath('message', 'Event already processed');

    Http::assertSentCount(4); // token, verify, order, verify
});

test('an event that fails signature verification is rejected', function () {
    $provider = paypalProvider(User::factory()->create());
    $transaction = pendingPaypalTransaction($provider);

    fakePaypalWebhook('FAILURE');

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertUnauthorized();

    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING)
        ->and(ProviderWebhookEvent::count())->toBe(0);
});

test('an event without transmission headers is rejected without calling paypal', function () {
    $provider = paypalProvider(User::factory()->create());

    Http::fake();

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent())->assertUnauthorized();

    Http::assertNothingSent();
});

test('a verified event is answered at once and handled by a queued job', function () {
    $provider = paypalProvider(User::factory()->create());
    pendingPaypalTransaction($provider);

    Queue::fake();
    fakePaypalWebhook();

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertOk()
        ->assertJsonPath('message', 'Event received');

    // Only the signature was checked; the order is read by the job.
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v2/checkout/orders'));
    Queue::assertPushed(ProcessPaypalWebhookEvent::class, fn (ProcessPaypalWebhookEvent $job) => $job->event->is(ProviderWebhookEvent::sole()));
});

test('a paypal outage while handling an event is retried by the job', function () {
    $provider = paypalProvider(User::factory()->create());
    $transaction = pendingPaypalTransaction($provider);

    Queue::fake();
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        '*/v2/checkout/orders/ORDER-1' => Http::response(['name' => 'INTERNAL_SERVER_ERROR'], 503),
    ]);

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())->assertOk();

    $job = new ProcessPaypalWebhookEvent(ProviderWebhookEvent::sole());

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class)
        ->and($job->tries)->toBeGreaterThan(1)
        ->and($transaction->refresh()->status)->toBe(TransactionStatus::PENDING)
        ->and(ProviderWebhookEvent::sole()->processed_at)->toBeNull();
});

/**
 * A refund-shaped event (PAYMENT.CAPTURE.REFUNDED or REVERSED): it has no
 * order id, only a link up to the capture.
 *
 * @return array<string, mixed>
 */
function captureRefundEvent(string $type, string $id = 'WH-REFUND-1'): array
{
    return [
        'id' => $id,
        'event_type' => $type,
        'resource_type' => 'refund',
        'resource' => [
            'id' => 'REFUND-9',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
            'links' => [
                ['rel' => 'self', 'href' => 'https://api.sandbox.paypal.com/v2/payments/refunds/REFUND-9'],
                ['rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/CAPTURE-1'],
            ],
        ],
    ];
}

test('a refund made in paypal is matched to its payment by the capture and recorded', function () {
    $provider = paypalProvider(User::factory()->create());
    $payment = pendingPaypalTransaction($provider, [
        'status' => TransactionStatus::SUCCESS,
        'provider_response' => ['order' => paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED')],
    ]);

    $refunded = paypalOrder('ORDER-1', 'COMPLETED', 'REFUNDED');
    $refunded['purchase_units'][0]['payments']['refunds'] = [
        ['id' => 'REFUND-9', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => '25.00']],
    ];
    fakePaypalWebhook(order: $refunded);

    $this->postJson(route('webhooks.paypal', $provider), captureRefundEvent('PAYMENT.CAPTURE.REFUNDED'), paypalWebhookHeaders())->assertOk();

    expect(Transaction::where('refund_of_id', $payment->id)->sole()->provider_transaction_id)->toBe('REFUND-9')
        ->and(ProviderWebhookEvent::sole()->processed_at)->not->toBeNull();
});

test('a reversed payment is noted once on the transfer it funded', function () {
    $user = User::factory()->create();
    $provider = paypalProvider($user);
    $payment = pendingPaypalTransaction($provider, [
        'status' => TransactionStatus::SUCCESS,
        'provider_response' => ['order' => paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED')],
    ]);
    $transfer = CrossBorderTransfer::create([
        'reference' => (string) Str::uuid(), 'user_id' => $user->id, 'funding_provider_id' => $provider->id,
        'funding_transaction_id' => $payment->id, 'source_currency' => 'USD', 'source_amount_minor' => 2500,
        'target_currency' => 'ZMW', 'target_amount_minor' => 67500, 'recipient_country' => 'ZM',
        'recipient_account' => '260977000001', 'fx_base_rate' => '27.5', 'fx_rate' => '27', 'status' => CrossBorderStatus::DELIVERED,
    ]);

    fakePaypalWebhook(order: paypalOrder('ORDER-1', 'COMPLETED', 'REVERSED'));

    foreach (['WH-REVERSE-1', 'WH-REVERSE-2'] as $eventId) {
        $this->postJson(route('webhooks.paypal', $provider), captureRefundEvent('PAYMENT.CAPTURE.REVERSED', $eventId), paypalWebhookHeaders())->assertOk();
    }

    $notes = $transfer->events()->pluck('note')->filter(fn (?string $note) => str_starts_with((string) $note, "PayPal reversed the sender's payment"));

    expect($notes)->toHaveCount(1)
        ->and($notes->first())->toContain('PayPal reference REFUND-9')
        ->and($transfer->refresh()->status)->toBe(CrossBorderStatus::DELIVERED)
        ->and($payment->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('a refund event for an unknown capture is acknowledged', function () {
    $provider = paypalProvider(User::factory()->create());

    fakePaypalWebhook();

    $this->postJson(route('webhooks.paypal', $provider), captureRefundEvent('PAYMENT.CAPTURE.REFUNDED'), paypalWebhookHeaders())->assertOk();

    expect(ProviderWebhookEvent::sole()->processed_at)->not->toBeNull();
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v2/checkout/orders'));
});

test('each paypal provider shows the webhook url to paste into paypal', function () {
    $user = User::factory()->create();
    $paypal = paypalProvider($user);
    PaymentProvider::create([
        'user_id' => $user->id, 'name' => 'DPO', 'class' => DpoController::class,
        'config' => ['company_token' => 't', 'service_type' => '1', 'supported_countries' => ['ZM']], 'is_active' => true,
    ]);

    $this->actingAs($user)->get(route('providers.index'))
        ->assertInertia(fn ($page) => $page
            ->where('providers.0.webhook_url', route('webhooks.paypal', $paypal))
            ->where('providers.1.webhook_url', null));
});

test('events for unknown orders are acknowledged', function () {
    $provider = paypalProvider(User::factory()->create());

    fakePaypalWebhook();

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertOk();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/v2/checkout/orders'));
});

test('webhooks require a configured webhook id', function () {
    $provider = paypalProvider(User::factory()->create(), ['webhook_id' => null]);

    Http::fake();

    $this->postJson(route('webhooks.paypal', $provider), captureCompletedEvent(), paypalWebhookHeaders())
        ->assertStatus(422)
        ->assertJsonPath('message', 'Webhooks are not configured for this provider');

    Http::assertNothingSent();
});

test('the paypal webhook endpoint ignores other providers', function () {
    $user = User::factory()->create();
    $dpo = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'DPO',
        'class' => DpoController::class,
        'config' => ['company_token' => 't', 'service_type' => '1', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    $this->postJson(route('webhooks.paypal', $dpo), captureCompletedEvent(), paypalWebhookHeaders())->assertNotFound();
});
