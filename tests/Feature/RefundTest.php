<?php

use App\Enums\CrossBorderStatus;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\LencoController;
use App\Models\CrossBorderTransfer;
use App\Models\LedgerEntry;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TransactionVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Sleep::fake();

    $this->user = User::factory()->create(['api_token' => 'refund-token']);
    $this->paypal = paypalProvider($this->user);
    $this->payment = pendingPaypalTransaction($this->paypal, [
        'status' => TransactionStatus::SUCCESS,
        'provider_response' => ['order' => paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED')],
    ]);
});

function fakePaypalRefunds(?array $refund = null, int $status = 201): void
{
    $count = 0;

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        // Each PayPal refund gets its own id, as in PayPal.
        '*/v2/payments/captures/CAPTURE-1/refund' => function () use ($refund, $status, &$count) {
            return Http::response($refund ?? ['id' => 'REFUND-'.(++$count), 'status' => 'COMPLETED'], $status);
        },
    ]);
}

function refundPayment(array $body = [], array $headers = [])
{
    return test()->withToken('refund-token')->withHeaders($headers)->postJson('/api/v1/payment/refund', [
        'transaction_id' => test()->payment->transaction_id,
        ...$body,
    ]);
}

test('a payment is refunded in full through paypal and the ledger', function () {
    fakePaypalRefunds();

    $response = refundPayment(['reason' => 'Order cancelled'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.amount', '25.00')
        ->assertJsonPath('data.reason', 'Order cancelled')
        ->assertJsonPath('data.payment.refunded', '25.00')
        ->assertJsonPath('data.payment.refundable', '0.00');

    $refund = Transaction::where('transaction_id', $response->json('data.refund_id'))->sole();

    expect($refund->direction)->toBe('debit')
        ->and($refund->refund_of_id)->toBe($this->payment->id)
        ->and($refund->provider_transaction_id)->toBe('REFUND-1')
        ->and(LedgerEntry::where('type', LedgerEntryType::REFUND->value)->where('account', 'provider')->value('amount_minor'))->toBe(-2500);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/payments/captures/CAPTURE-1/refund')
        && $request->hasHeader('PayPal-Request-Id', 'refund-'.$refund->transaction_id)
        && $request['amount'] === ['value' => '25.00', 'currency_code' => 'USD']
        && $request['custom_id'] === $refund->transaction_id
        && $request['note_to_payer'] === 'Order cancelled');
});

test('partial refunds can never add up to more than was paid', function () {
    fakePaypalRefunds();

    refundPayment(['amount' => 10])->assertOk()->assertJsonPath('data.payment.refundable', '15.00');

    refundPayment(['amount' => 20])
        ->assertStatus(422)
        ->assertJsonPath('message', 'At most 15.00 USD can still be refunded.');

    refundPayment()->assertOk()->assertJsonPath('data.amount', '15.00');

    refundPayment()
        ->assertStatus(422)
        ->assertJsonPath('message', 'This payment has already been refunded in full.');

    refundPayment(['amount' => 1.234])->assertStatus(422);
});

test('an idempotency key returns the same refund instead of refunding again', function () {
    fakePaypalRefunds();

    $first = refundPayment(['amount' => 5], ['Idempotency-Key' => 'refund-5'])->json('data.refund_id');
    refundPayment(['amount' => 5], ['Idempotency-Key' => 'refund-5'])->assertJsonPath('data.refund_id', $first);

    expect(Transaction::whereNotNull('refund_of_id')->count())->toBe(1);
    Http::assertSentCount(2); // token + one refund
});

test('a refund paypal refuses fails and frees the amount again', function () {
    fakePaypalRefunds(['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'Refund refused', 'details' => [['issue' => 'REFUND_NOT_ALLOWED']]], 422);

    refundPayment()->assertStatus(422)->assertJsonPath('status', 'failed');

    expect(Transaction::whereNotNull('refund_of_id')->sole()->status)->toBe(TransactionStatus::FAILED);

    refundPayment()->assertStatus(422); // tried again, still refused, but allowed to try
});

test('an unconfirmed refund stays pending and verifying it asks paypal again with the same request', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/payments/captures/CAPTURE-1/refund' => Http::sequence()
            ->push(['name' => 'INTERNAL_SERVER_ERROR'], 500)
            ->push(['name' => 'INTERNAL_SERVER_ERROR'], 500)
            ->push(['name' => 'INTERNAL_SERVER_ERROR'], 500)
            ->push(['name' => 'INTERNAL_SERVER_ERROR'], 500)
            ->push(['id' => 'REFUND-9', 'status' => 'COMPLETED'], 201),
    ]);

    $id = refundPayment()->assertOk()->assertJsonPath('status', 'pending')->json('data.refund_id');

    // While it's unconfirmed, the amount stays reserved.
    refundPayment(['amount' => 1])->assertStatus(422);

    $this->withToken('refund-token')->postJson('/api/v1/payment/refund/verify', ['refund_id' => $id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.reference', 'REFUND-9');

    $requestIds = Http::recorded()->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), '/refund'))
        ->map(fn (Request $request) => $request->header('PayPal-Request-Id')[0] ?? null)
        ->unique();

    expect($requestIds->values()->all())->toBe(['refund-'.$id]);
});

test('refunds made in the paypal dashboard are recorded when the payment is checked', function () {
    $order = paypalOrder('ORDER-1', 'COMPLETED', 'PARTIALLY_REFUNDED');
    $order['purchase_units'][0]['payments']['refunds'] = [
        ['id' => 'PAYPAL-REFUND-1', 'status' => 'COMPLETED', 'amount' => ['value' => '7.50', 'currency_code' => 'USD']],
    ];

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1' => Http::response($order),
    ]);

    app(TransactionVerifier::class)->verify($this->payment);
    app(TransactionVerifier::class)->verify($this->payment->refresh());

    $refund = Transaction::whereNotNull('refund_of_id')->sole();

    expect($this->payment->refresh()->status)->toBe(TransactionStatus::SUCCESS)
        ->and($refund->provider_transaction_id)->toBe('PAYPAL-REFUND-1')
        ->and((float) $refund->amount)->toBe(7.5)
        ->and($refund->status)->toBe(TransactionStatus::SUCCESS)
        ->and($refund->provider_response['source'])->toBe('paypal');

    fakePaypalRefunds();
    refundPayment(['amount' => 20])->assertStatus(422)->assertJsonPath('message', 'At most 17.50 USD can still be refunded.');
});

test('our own refund is matched when paypal reports it on the order', function () {
    fakePaypalRefunds(['name' => 'INTERNAL_SERVER_ERROR'], 500);
    $id = refundPayment(['amount' => 5])->assertJsonPath('status', 'pending')->json('data.refund_id');

    $order = paypalOrder('ORDER-1', 'COMPLETED', 'PARTIALLY_REFUNDED');
    $order['purchase_units'][0]['payments']['refunds'] = [
        ['id' => 'REFUND-5', 'custom_id' => $id, 'status' => 'COMPLETED', 'amount' => ['value' => '5.00', 'currency_code' => 'USD']],
    ];
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1' => Http::response($order),
    ]);

    app(TransactionVerifier::class)->verify($this->payment);

    $refund = Transaction::whereNotNull('refund_of_id')->sole();
    expect($refund->transaction_id)->toBe($id)
        ->and($refund->provider_transaction_id)->toBe('REFUND-5')
        ->and($refund->status)->toBe(TransactionStatus::SUCCESS);
});

test('only completed payments from refund-capable providers can be refunded', function () {
    Http::fake();
    $this->payment->update(['status' => TransactionStatus::PENDING]);
    refundPayment()->assertStatus(422)->assertJsonPath('message', 'Only a completed payment can be refunded.');

    $lenco = PaymentProvider::create(['user_id' => $this->user->id, 'name' => 'Lenco', 'class' => LencoController::class, 'config' => ['api_key' => 'k'], 'is_active' => true]);
    $mobile = pendingPaypalTransaction($lenco, ['provider_transaction_id' => 'LENCO-1', 'status' => TransactionStatus::SUCCESS]);

    refundPayment(['transaction_id' => $mobile->transaction_id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Lenco does not support refunds.');

    Http::assertNothingSent();
});

test('a payment funding a cross-border transfer is never refunded by hand', function () {
    Http::fake();
    CrossBorderTransfer::create([
        'reference' => 'cb-1', 'user_id' => $this->user->id, 'funding_provider_id' => $this->paypal->id,
        'funding_transaction_id' => $this->payment->id, 'source_currency' => 'USD', 'source_amount_minor' => 2500,
        'target_currency' => 'ZMW', 'target_amount_minor' => 67700, 'recipient_country' => 'ZM', 'recipient_account' => '260977123456',
        'fx_base_rate' => '27.5', 'fx_rate' => '27.0875', 'status' => CrossBorderStatus::DELIVERED,
    ]);

    refundPayment()
        ->assertStatus(422)
        ->assertJsonPath('message', "This payment funds cross-border transfer cb-1 (Delivered). It's refunded automatically if delivery fails, so it can't be refunded by hand.");
});

test('another account cannot refund the payment', function () {
    Http::fake();
    User::factory()->create(['api_token' => 'other-token']);

    $this->withToken('other-token')
        ->postJson('/api/v1/payment/refund', ['transaction_id' => $this->payment->transaction_id])
        ->assertNotFound();
});

test('the payments page shows refunds and refunds from the dashboard', function () {
    fakePaypalRefunds();
    $this->actingAs($this->user);

    $this->get(route('payments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.data.0.refund.canRefund', true)
            ->where('transactions.data.0.refund.refundable', '25.00'));

    $this->from(route('payments.index'))
        ->post(route('payments.refund', $this->payment->transaction_id), ['amount' => 5, 'reason' => 'Damaged'])
        ->assertRedirect(route('payments.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Refunded 5.00 USD.']);

    $this->get(route('payments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.data.0.refund.refunded', '5.00')
            ->where('transactions.data.0.refund.refundable', '20.00')
            ->where('transactions.data.0.refund.items.0.reason', 'Damaged'));

    $this->actingAs(User::factory()->create())
        ->post(route('payments.refund', $this->payment->transaction_id))
        ->assertNotFound();
});
