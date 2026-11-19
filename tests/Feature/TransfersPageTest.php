<?php

use App\Enums\CrossBorderStatus;
use App\Enums\TransactionStatus;
use App\Models\ApiLog;
use App\Models\CrossBorderTransfer;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\ProviderLog;
use App\Models\Transaction;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function transfersProvider(User $user, string $name = 'Lenco'): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => $name.' '.$user->id,
        'class' => 'App\Http\Controllers\Providers\SandboxPayoutController',
        'config' => ['api_key' => 'key', 'supported_countries' => ['ZM', 'MW']],
        'is_active' => true,
    ]);
}

function makePayoutTransaction(PaymentProvider $provider, Customer $customer, array $attrs = []): Transaction
{
    static $payoutSeq = 0;
    $payoutSeq++;

    return Transaction::create([
        'transaction_id' => 'payout-tx-'.$payoutSeq,
        'payment_provider_id' => $provider->id,
        'provider_transaction_id' => 'prov-payout-'.$payoutSeq,
        'customer_id' => $customer->id,
        'amount' => 500,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::SUCCESS,
        'direction' => 'debit',
        'is_fx' => false,
        ...$attrs,
    ]);
}

function makeCrossBorderTransfer(User $user, PaymentProvider $fundingProvider, PaymentProvider $payoutProvider, array $attrs = []): CrossBorderTransfer
{
    static $cbSeq = 0;
    $cbSeq++;

    return CrossBorderTransfer::create([
        'reference' => 'XFER-TEST-'.$cbSeq,
        'user_id' => $user->id,
        'origin' => 'web',
        'source_currency' => 'USD',
        'source_amount_minor' => 10000, // $100.00
        'target_currency' => 'ZMW',
        'target_amount_minor' => 270000, // ZMW 2,700.00
        'recipient_country' => 'ZM',
        'recipient_account' => '0977123456',
        'recipient_name' => 'Alice Phiri',
        'recipient_network' => 'airtel',
        'funding_provider_id' => $fundingProvider->id,
        'payout_provider_id' => $payoutProvider->id,
        'fx_base_rate' => '27.00000000',
        'fx_rate' => '27.00000000',
        'status' => CrossBorderStatus::DELIVERED,
        ...$attrs,
    ]);
}

test('guests cannot view the transfers page', function () {
    $this->get(route('transfers.index'))->assertRedirect(route('login'));
});

test('the transfers page renders an empty state for new users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('transfers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('transfers/index')
            ->where('metrics.total', 0)
            ->where('metrics.successRate', null)
            ->where('metrics.crossBorderCount', 0)
            ->where('metrics.payoutCount', 0)
            ->has('metricTrend.total', 14)
            ->has('metricTrend.success', 14)
            ->has('metricTrend.pending', 14)
            ->has('metricTrend.failed', 14)
            ->has('topCurrencies', 0)
            ->has('topCountries', 0)
            ->has('transfers.data', 0)
        );
});

test('the transfers page aggregates cross-border transfers and direct payouts scoped to user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $customer = Customer::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $funding = transfersProvider($user, 'PayPal');
    $payout = transfersProvider($user, 'Lenco');

    // User's cross-border transfers
    makeCrossBorderTransfer($user, $funding, $payout, [
        'status' => CrossBorderStatus::DELIVERED,
        'source_amount_minor' => 10000, // 100 USD
        'target_currency' => 'ZMW',
        'recipient_country' => 'ZM',
    ]);
    makeCrossBorderTransfer($user, $funding, $payout, [
        'status' => CrossBorderStatus::AWAITING_PAYMENT,
        'source_amount_minor' => 5000, // 50 USD
        'target_currency' => 'MWK',
        'recipient_country' => 'MW',
    ]);

    // User's direct payout transactions
    makePayoutTransaction($payout, $customer, [
        'amount' => 200,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::SUCCESS,
    ]);
    makePayoutTransaction($payout, $customer, [
        'amount' => 75,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::FAILED,
    ]);

    // Another user's transfers must not be counted
    $otherFunding = transfersProvider($other, 'PayPal Other');
    $otherPayout = transfersProvider($other, 'Lenco Other');
    makeCrossBorderTransfer($other, $otherFunding, $otherPayout);
    makePayoutTransaction($otherPayout, $customer);

    $this->actingAs($user)
        ->get(route('transfers.index', ['range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.total', 4)
            ->where('metrics.crossBorderCount', 2)
            ->where('metrics.payoutCount', 2)
            ->where('metrics.success', 2) // 1 CB delivered + 1 payout success
            ->where('metrics.pending', 1) // 1 CB awaiting payment
            ->where('metrics.failed', 1)  // 1 payout failed
            ->where('metrics.successRate', 50)
            // Delivered in each recipient currency: 2,700 from the transfer + 200 paid out.
            ->where('metrics.volumes.0', ['currency' => 'ZMW', 'amount' => 2900, 'count' => 2])
            ->has('metrics.volumes', 6)
            ->has('transfers.data', 4)
            ->where('topCountries.0.code', 'ZM')
            ->where('topCountries.0.count', 3)
        );
});

test('the transfers table can be filtered by type', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);
    $funding = transfersProvider($user, 'PayPal');
    $payout = transfersProvider($user, 'Lenco');

    makeCrossBorderTransfer($user, $funding, $payout);
    makeCrossBorderTransfer($user, $funding, $payout);
    makePayoutTransaction($payout, $customer);

    // Filter by cross_border
    $this->actingAs($user)
        ->get(route('transfers.index', ['type' => 'cross_border', 'range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.type', 'cross_border')
            ->has('transfers.data', 2)
            ->where('transfers.data.0.type', 'cross_border')
        );

    // Filter by payout
    $this->actingAs($user)
        ->get(route('transfers.index', ['type' => 'payout', 'range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.type', 'payout')
            ->has('transfers.data', 1)
            ->where('transfers.data.0.type', 'payout')
        );
});

test('the transfers table can be filtered by status', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'David', 'email' => 'david@example.com']);
    $funding = transfersProvider($user, 'PayPal');
    $payout = transfersProvider($user, 'Lenco');

    makeCrossBorderTransfer($user, $funding, $payout, ['status' => CrossBorderStatus::DELIVERED]);
    makeCrossBorderTransfer($user, $funding, $payout, ['status' => CrossBorderStatus::PAYMENT_FAILED]);
    makePayoutTransaction($payout, $customer, ['status' => TransactionStatus::FAILED]);

    $this->actingAs($user)
        ->get(route('transfers.index', ['status' => 'failed', 'range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.status', 'failed')
            ->has('transfers.data', 2)
            ->where('metrics.total', 3) // Metrics reflect window total
        );
});

test('the transfers table can be searched by recipient and reference', function () {
    $user = User::factory()->create();
    $funding = transfersProvider($user, 'PayPal');
    $payout = transfersProvider($user, 'Lenco');

    makeCrossBorderTransfer($user, $funding, $payout, [
        'reference' => 'XFER-FIND-ME',
        'recipient_name' => 'UniqueRecipient',
        'recipient_account' => '260999888777',
    ]);
    makeCrossBorderTransfer($user, $funding, $payout, [
        'reference' => 'XFER-OTHER',
        'recipient_name' => 'SomeoneElse',
        'recipient_account' => '260111222333',
    ]);

    $this->actingAs($user)
        ->get(route('transfers.index', ['q' => 'UniqueRecipient', 'range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('transfers.data', 1)
            ->where('transfers.data.0.reference', 'XFER-FIND-ME')
            ->where('transfers.data.0.recipientName', 'UniqueRecipient')
        );
});

test('user can re-verify a payout transaction', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Eve', 'email' => 'eve@example.com']);
    $provider = transfersProvider($user);

    $tx = makePayoutTransaction($provider, $customer, ['status' => TransactionStatus::PENDING]);

    $this->actingAs($user)
        ->post(route('transfers.transactions.verify', $tx->transaction_id))
        ->assertRedirect();
});

test('transfer details payload includes timeline events, provider feedback, refund details, and logs link', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Frank', 'email' => 'frank@example.com']);
    $funding = transfersProvider($user, 'PayPal');
    $payout = transfersProvider($user, 'Lenco');

    $fundingTx = makePayoutTransaction($funding, $customer, [
        'direction' => 'credit',
        'status' => TransactionStatus::SUCCESS,
    ]);

    $payoutTx = makePayoutTransaction($payout, $customer, [
        'direction' => 'debit',
        'status' => TransactionStatus::FAILED,
        'provider_response' => ['message' => 'Destination mobile account is not registered'],
    ]);

    $refundTx = makePayoutTransaction($funding, $customer, [
        'direction' => 'debit',
        'status' => TransactionStatus::SUCCESS,
        'provider_transaction_id' => 'PAYPAL-REFUND-999',
    ]);

    $transfer = makeCrossBorderTransfer($user, $funding, $payout, [
        'reference' => 'XFER-TRACEABLE-001',
        'status' => CrossBorderStatus::REFUNDED,
        'funding_transaction_id' => $fundingTx->id,
        'payout_transaction_id' => $payoutTx->id,
        'refund_transaction_id' => $refundTx->id,
        'failure_reason' => 'Every payout provider refused the payout: Destination mobile account is not registered',
    ]);

    $transfer->events()->createMany([
        ['from_status' => null, 'to_status' => CrossBorderStatus::AWAITING_PAYMENT, 'note' => 'Quoted at 1 USD = 27 ZMW', 'created_at' => now()->subMinutes(10)],
        ['from_status' => CrossBorderStatus::AWAITING_PAYMENT, 'to_status' => CrossBorderStatus::FUNDED, 'note' => 'PayPal payment captured', 'created_at' => now()->subMinutes(8)],
        ['from_status' => CrossBorderStatus::FUNDED, 'to_status' => CrossBorderStatus::PAYOUT_FAILED, 'note' => 'Every payout provider refused the payout', 'created_at' => now()->subMinutes(5)],
        ['from_status' => CrossBorderStatus::PAYOUT_FAILED, 'to_status' => CrossBorderStatus::REFUNDED, 'note' => 'Refunded to the sender', 'created_at' => now()->subMinutes(2)],
    ]);

    $this->actingAs($user)
        ->get(route('transfers.index', ['range' => 'all']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('transfers.data.0.reference', 'XFER-TRACEABLE-001')
            ->where('transfers.data.0.status', 'refunded')
            ->where('transfers.data.0.failureReason', 'Every payout provider refused the payout: Destination mobile account is not registered')
            ->where('transfers.data.0.providerFeedback', 'Destination mobile account is not registered')
            ->where('transfers.data.0.refundLeg.providerReference', 'PAYPAL-REFUND-999')
            ->where('transfers.data.0.refundLeg.status', 'success')
            ->where('transfers.data.0.logsUrl', url('/logs?q=XFER-TRACEABLE-001'))
            ->has('transfers.data.0.events', 4)
            ->where('transfers.data.0.events.0.note', 'Quoted at 1 USD = 27 ZMW')
            ->where('transfers.data.0.events.3.to', 'refunded')
        );
});

test('api logs can be searched by transfer reference and correlated provider mno calls', function () {
    $user = User::factory()->create();
    $provider = transfersProvider($user, 'Lenco');
    $requestId = (string) Str::uuid();

    ApiLog::create([
        'user_id' => $user->id,
        'request_id' => $requestId,
        'method' => 'POST',
        'url' => url('/transfers/XFER-SEARCH-999/disburse'),
        'route' => 'transfers.disburse',
        'response_status' => 422,
        'response_body' => json_encode([
            'reference' => 'XFER-SEARCH-999',
            'status' => 'payout_failed',
            'failure_reason' => 'Every payout provider refused the payout: Invalid recipient wallet',
        ]),
        'duration_ms' => 450,
        'created_at' => now(),
    ]);

    ProviderLog::create([
        'request_id' => $requestId,
        'payment_provider_id' => $provider->id,
        'method' => 'POST',
        'host' => 'api.lenco.co',
        'url' => 'https://api.lenco.co/access/v2/transfers',
        'request_body' => json_encode(['account' => '0977000111']),
        'response_status' => 422,
        'response_body' => json_encode(['message' => 'Invalid recipient wallet']),
        'failed' => true,
        'error_message' => 'HTTP 422: Invalid recipient wallet',
        'duration_ms' => 380,
        'created_at' => now(),
    ]);

    // Search by transfer reference
    $this->actingAs($user)
        ->get(route('logs.index', ['q' => 'XFER-SEARCH-999', 'range' => 'today']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.mnoCalls.0.url', 'https://api.lenco.co/access/v2/transfers')
            ->where('logs.data.0.mnoCalls.0.failed', true)
        );

    // Search by provider call error message
    $this->actingAs($user)
        ->get(route('logs.index', ['q' => 'Invalid recipient wallet', 'range' => 'today']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs.data', 1)
            ->where('logs.data.0.route', 'transfers.disburse')
        );
});

test('transfer volume is shown per delivered currency, never added across currencies', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Dee', 'email' => 'dee@example.com']);
    $funding = transfersProvider($user, 'PayPal');
    $payout = transfersProvider($user, 'Lenco');

    makeCrossBorderTransfer($user, $funding, $payout, ['status' => CrossBorderStatus::DELIVERED, 'target_currency' => 'ZMW', 'target_amount_minor' => 100]);
    makeCrossBorderTransfer($user, $funding, $payout, ['status' => CrossBorderStatus::DELIVERED, 'target_currency' => 'KES', 'target_amount_minor' => 50000]);
    makeCrossBorderTransfer($user, $funding, $payout, ['status' => CrossBorderStatus::REFUNDED, 'target_currency' => 'UGX', 'target_amount_minor' => 900000]);
    makePayoutTransaction($payout, $customer, ['amount' => 1, 'currency' => 'ZMW', 'status' => TransactionStatus::SUCCESS]);

    $this->actingAs($user)
        ->get(route('transfers.index', ['range' => 'all']))
        ->assertInertia(fn (Assert $page) => $page
            // What reached wallets, in the recipient's currency; refunds add nothing.
            ->where('metrics.volumes.0', ['currency' => 'ZMW', 'amount' => 2, 'count' => 2])
            ->where('metrics.volumes.1', ['currency' => 'KES', 'amount' => 500, 'count' => 1])
            // UGX was only refunded: shown, but with no volume.
            ->where('metrics.volumes.2', ['currency' => 'UGX', 'amount' => 0, 'count' => 0])
            ->has('metrics.volumes', 6)
            ->where('metrics.volumes', fn ($volumes) => collect($volumes)->contains('currency', 'USD'))
            ->missing('metrics.volume'));
});
