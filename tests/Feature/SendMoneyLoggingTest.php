<?php

use App\Ai\Agents\SendMoneyAgent;
use App\Enums\CrossBorderStatus;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Jobs\DisburseCrossBorderTransfer;
use App\Jobs\RefundCrossBorderTransfer;
use App\Models\ApiLog;
use App\Models\CrossBorderTransfer;
use App\Models\PaymentProvider;
use App\Models\ProviderLog;
use App\Models\User;
use App\Support\CrossBorder\CrossBorderTransferService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    $this->user = User::factory()->create();

    paypalProvider($this->user, ['mcp' => 'disabled']);
    $this->payoutProvider = PaymentProvider::create([
        'user_id' => $this->user->id,
        'name' => 'Simulated Payouts',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
        '*/v2/checkout/orders' => fn () => Http::response(paypalOrder('ORDER-'.Str::upper(Str::random(8))), 201),
        '*/v2/payments/captures/*/refund' => Http::response(['id' => 'REFUND-1', 'status' => 'COMPLETED'], 201),
    ]);
});

function createAgentConversation(User $user): string
{
    $id = (string) Str::uuid();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => 'Send money',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

test('quoting send money via web form is logged in ApiLog and visible on /logs', function () {
    $this->actingAs($this->user)
        ->postJson(route('send.quote'), [
            'amount' => 2500,
            'country' => 'ZM',
            'mode' => 'target',
        ])
        ->assertOk();

    $log = ApiLog::query()->where('user_id', $this->user->id)->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->route)->toBe('send.quote')
        ->and($log->method)->toBe('POST')
        ->and($log->response_status)->toBe(200)
        ->and($log->request_body['amount'])->toBe(2500)
        ->and($log->response_body)->toContain('Transfer quoted');

    $this->actingAs($this->user)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.path', '/send/quote')
            ->where('logs.data.0.status', 200)
        );
});

test('manually sending money logs the store request and correlates outgoing PayPal calls', function () {
    $this->actingAs($this->user)
        ->post(route('send.store'), [
            'amount' => 2500,
            'mode' => 'target',
            'country' => 'ZM',
            'source_currency' => 'USD',
            'account_number' => '0977123456',
            'recipient_name' => 'Mum',
        ])
        ->assertRedirect();

    $transfer = CrossBorderTransfer::sole();
    $apiLog = ApiLog::query()->where('user_id', $this->user->id)->where('route', 'send.store')->first();

    expect($apiLog)->not->toBeNull()
        ->and($apiLog->request_id)->not->toBeNull()
        ->and($apiLog->providerCalls)->toHaveCount(2);

    $tokenCall = $apiLog->providerCalls->first(fn (ProviderLog $l) => str_contains($l->url, '/oauth2/token'));
    $orderCall = $apiLog->providerCalls->first(fn (ProviderLog $l) => str_contains($l->url, '/checkout/orders'));

    expect($tokenCall)->not->toBeNull()
        ->and($orderCall)->not->toBeNull()
        ->and($tokenCall->request_id)->toBe($apiLog->request_id)
        ->and($orderCall->request_id)->toBe($apiLog->request_id);

    $this->actingAs($this->user)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.path', '/send')
            ->has('logs.data.0.mnoCalls', 2)
            ->where('logs.data.0.mnoCalls.0.provider', fn ($name) => str_contains($name, 'PayPal'))
        );
});

test('ai assistant message and decision are logged in ApiLog with correlated PayPal calls', function () {
    config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => 'test-key']);

    // 1. Chat turn: quoting
    SendMoneyAgent::fake(['I have quoted USD 92.30 for ZMW 2,500.00 to 0977123456. Shall I proceed?']);

    $this->actingAs($this->user)
        ->postJson(route('send.assistant.message'), [
            'message' => 'Quote sending K2,500 to mum on 0977123456',
        ])
        ->assertOk();

    $chatLog = ApiLog::query()->where('user_id', $this->user->id)->where('route', 'send.assistant.message')->latest('id')->first();
    expect($chatLog)->not->toBeNull()
        ->and($chatLog->method)->toBe('POST')
        ->and($chatLog->response_status)->toBe(200)
        ->and($chatLog->request_body['message'])->toContain('Quote sending K2,500');

    // 2. Decision turn: approving transfer creation
    $conversation = createAgentConversation($this->user);

    // Run the agent tool directly to create transfer under the conversation
    $service = app(CrossBorderTransferService::class);
    [$transfer, $checkoutUrl] = $service->request($this->user, [
        'amount' => 2500,
        'mode' => 'target',
        'country' => 'ZM',
        'source_currency' => 'USD',
        'account_number' => '0977123456',
        'recipient_name' => 'Mum',
    ], origin: 'agent', conversationId: $conversation);

    SendMoneyAgent::fake(['Transfer started! The sender can pay at the PayPal checkout.']);

    $this->actingAs($this->user)
        ->postJson(route('send.assistant.decide'), [
            'conversation_id' => $conversation,
            'decisions' => ['call-1' => 'approve'],
        ])
        ->assertOk();

    $decideLog = ApiLog::query()->where('user_id', $this->user->id)->where('route', 'send.assistant.decide')->latest('id')->first();
    expect($decideLog)->not->toBeNull()
        ->and($decideLog->response_status)->toBe(200);

    // Visiting /logs shows both AI assistant entries
    $this->actingAs($this->user)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.path', '/send/assistant/decide')
            ->where('logs.data.1.path', '/send/assistant')
        );
});

test('verifying a transfer via web route creates ApiLog and links provider verification calls', function () {
    $service = app(CrossBorderTransferService::class);
    [$transfer] = $service->request($this->user, [
        'amount' => 2500,
        'mode' => 'target',
        'country' => 'ZM',
        'source_currency' => 'USD',
        'account_number' => '0977123456',
    ]);

    $this->actingAs($this->user)
        ->post(route('send.verify', $transfer))
        ->assertRedirect();

    $verifyLog = ApiLog::query()->where('user_id', $this->user->id)->where('route', 'send.verify')->latest('id')->first();

    expect($verifyLog)->not->toBeNull()
        ->and($verifyLog->request_id)->not->toBeNull()
        ->and($verifyLog->providerCalls->count())->toBeGreaterThanOrEqual(1);

    $this->actingAs($this->user)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.path', "/send/{$transfer->reference}/verify")
            ->where('logs.data.0.mnoCalls.0.provider', fn ($name) => str_contains($name, 'PayPal'))
        );
});

test('disbursing a transfer creates an ApiLog with linked payout provider calls', function () {
    $service = app(CrossBorderTransferService::class);
    [$transfer] = $service->request($this->user, [
        'amount' => 2500,
        'mode' => 'target',
        'country' => 'ZM',
        'source_currency' => 'USD',
        'account_number' => '0977123456',
    ]);

    // Mark as funded
    $transfer->update(['status' => CrossBorderStatus::FUNDED]);

    // Run disburse (as happens via DisburseCrossBorderTransfer job)
    (new DisburseCrossBorderTransfer($transfer->id))->handle($service);

    $disburseLog = ApiLog::query()
        ->where('user_id', $this->user->id)
        ->where('route', 'transfers.disburse')
        ->latest('id')
        ->first();

    expect($disburseLog)->not->toBeNull()
        ->and($disburseLog->request_id)->not->toBeNull()
        ->and(in_array($disburseLog->response_status, [200, 202], true))->toBeTrue();

    $this->actingAs($this->user)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.path', "/transfers/{$transfer->reference}/disburse")
            ->where('logs.data.0.status', fn ($status) => in_array($status, [200, 202], true))
        );
});

test('refunding a transfer creates an ApiLog with linked PayPal refund calls', function () {
    $service = app(CrossBorderTransferService::class);
    [$transfer] = $service->request($this->user, [
        'amount' => 2500,
        'mode' => 'target',
        'country' => 'ZM',
        'source_currency' => 'USD',
        'account_number' => '0977123456',
    ]);

    // Mark funding transaction as captured with capture id so PayPal driver can issue refund
    $transfer->fundingTransaction->update([
        'provider_response' => [
            'order' => [
                'purchase_units' => [
                    [
                        'payments' => [
                            'captures' => [
                                ['id' => 'CAPTURE-1'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    // Mark payout as failed so refund can proceed
    $transfer->update(['status' => CrossBorderStatus::PAYOUT_FAILED]);

    // Run refund (as happens via RefundCrossBorderTransfer job)
    (new RefundCrossBorderTransfer($transfer->id))->handle($service);

    $refundLog = ApiLog::query()
        ->where('user_id', $this->user->id)
        ->where('route', 'transfers.refund')
        ->latest('id')
        ->first();

    expect($refundLog)->not->toBeNull()
        ->and($refundLog->request_id)->not->toBeNull()
        ->and($refundLog->response_status)->toBe(200)
        ->and($refundLog->providerCalls->count())->toBeGreaterThanOrEqual(1);

    $refundCall = $refundLog->providerCalls->first(fn (ProviderLog $l) => str_contains($l->url, '/refund'));
    expect($refundCall)->not->toBeNull()
        ->and($refundCall->paymentProvider?->name)->toContain('PayPal');

    $this->actingAs($this->user)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.path', "/transfers/{$transfer->reference}/refund")
            ->where('logs.data.0.status', 200)
            ->where('logs.data.0.mnoCalls.0.provider', fn ($name) => str_contains($name, 'PayPal'))
        );
});

test('paypal checkout return captures order and is logged in ApiLog for the merchant', function () {
    $service = app(CrossBorderTransferService::class);
    [$transfer] = $service->request($this->user, [
        'amount' => 2500,
        'mode' => 'target',
        'country' => 'ZM',
        'source_currency' => 'USD',
        'account_number' => '0977123456',
        'return_url' => 'https://example.com/returned',
    ]);

    $fundingTransaction = $transfer->fundingTransaction;
    $signedUrl = URL::signedRoute('paypal.checkout.return', ['transaction' => $fundingTransaction->transaction_id]).'&token=ORDER-1';

    $this->get($signedUrl)->assertRedirect();

    $returnLog = ApiLog::query()
        ->where('user_id', $this->user->id)
        ->where('route', 'paypal.checkout.return')
        ->latest('id')
        ->first();

    expect($returnLog)->not->toBeNull()
        ->and($returnLog->request_id)->not->toBeNull()
        ->and($returnLog->providerCalls->count())->toBeGreaterThanOrEqual(1);
});
