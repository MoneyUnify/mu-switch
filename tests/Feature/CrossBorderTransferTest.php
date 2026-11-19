<?php

use App\Enums\CrossBorderStatus;
use App\Enums\LedgerEntryType;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Jobs\DisburseCrossBorderTransfer;
use App\Models\CrossBorderTransfer;
use App\Models\LedgerEntry;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\Fx\FxQuoter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();

    $this->user = User::factory()->create(['api_token' => 'cb-token']);
    $this->paypal = paypalProvider($this->user);
    $this->wallets = PaymentProvider::create([
        'user_id' => $this->user->id,
        'name' => 'Simulated Payouts',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
});

/**
 * PayPal sandbox responses: create the order, see it approved, capture it,
 * and refund the capture.
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeCrossBorderPaypal(array $overrides = []): void
{
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
        '*/v2/checkout/orders' => Http::response(paypalOrder(), 201),
        '*/v2/payments/captures/CAPTURE-1/refund' => Http::response(['id' => 'REFUND-1', 'status' => 'COMPLETED'], 201),
        ...$overrides,
    ]);
}

function startTransfer(array $body = [], array $headers = [])
{
    return test()->withToken('cb-token')->withHeaders($headers)->postJson('/api/v1/transfer/request', [
        'amount' => 2500,
        'country' => 'ZM',
        'account_number' => '0977123456',
        'recipient_name' => 'Mum',
        'payer_email' => 'chipo@example.com',
        ...$body,
    ]);
}

function verifyTransfer(string $reference)
{
    return test()->withToken('cb-token')->postJson('/api/v1/transfer/verify', ['reference' => $reference]);
}

test('a quote shows what the sender pays, what arrives, the fx and the route, and creates nothing', function () {
    Http::fake();

    $this->withToken('cb-token')->postJson('/api/v1/transfer/quote', ['amount' => 2500, 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('data.sender_pays', ['amount' => '92.30', 'currency' => 'USD'])
        ->assertJsonPath('data.recipient_gets', ['amount' => '2500.00', 'currency' => 'ZMW'])
        ->assertJsonPath('data.fx.effective_rate', '27.08750000')
        ->assertJsonPath('data.funding_provider', $this->paypal->name)
        ->assertJsonPath('data.payout_route', ['Simulated Payouts'])
        ->assertJsonPath('data.operator_fees.payout', ['amount' => '25.00', 'currency' => 'ZMW']);

    expect(CrossBorderTransfer::count())->toBe(0);
    Http::assertNothingSent();
});

test('a transfer is paid in through paypal, then delivered to mobile money at the locked rate', function () {
    fakeCrossBorderPaypal();

    $reference = startTransfer()
        ->assertOk()
        ->assertJsonPath('status', 'awaiting_payment')
        ->assertJsonPath('data.checkout_url', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1')
        ->assertJsonPath('data.sender_pays.amount', '92.30')
        ->assertJsonPath('data.recipient_gets.amount', '2500.00')
        ->json('data.reference');

    $transfer = CrossBorderTransfer::sole();
    $funding = $transfer->fundingTransaction;
    expect($funding->currency)->toBe('USD')
        ->and((float) $funding->amount)->toBe(92.3)
        ->and($funding->is_fx)->toBeTruthy()
        ->and((float) $funding->fx_rate)->toBe(27.0875);

    // The sender approved on PayPal: verifying captures it and starts the payout.
    verifyTransfer($reference)
        ->assertOk()
        ->assertJsonPath('status', 'paying_out')
        ->assertJsonPath('data.funding.status', 'success')
        ->assertJsonPath('data.payout.provider', 'Simulated Payouts');

    $payout = $transfer->refresh()->payoutTransaction;
    expect($payout->direction)->toBe('debit')
        ->and($payout->currency)->toBe('ZMW')
        ->and((float) $payout->amount)->toBe(2500.0)
        ->and($payout->is_fx)->toBeTruthy();

    // The payout provider confirms delivery.
    verifyTransfer($reference)
        ->assertOk()
        ->assertJsonPath('status', 'delivered')
        ->assertJsonPath('data.timeline.*.status', ['awaiting_payment', 'funded', 'paying_out', 'delivered']);

    // The ledger shows USD in at PayPal and ZMW out of the payout float.
    expect(LedgerEntry::where('type', LedgerEntryType::COLLECTION->value)->where('payment_provider_id', $this->paypal->id)->value('amount_minor'))->toBe(9230)
        ->and(LedgerEntry::where('type', LedgerEntryType::PAYOUT->value)->where('payment_provider_id', $this->wallets->id)->where('account', 'provider')->value('amount_minor'))->toBe(-250000);
});

test('a refused payout refunds the sender through paypal', function () {
    fakeCrossBorderPaypal();

    // The simulated driver refuses numbers ending 0000.
    $reference = startTransfer(['account_number' => '0977120000'])->json('data.reference');

    verifyTransfer($reference)
        ->assertOk()
        ->assertJsonPath('status', 'refunded')
        ->assertJsonPath('data.refund.status', 'success');

    $transfer = CrossBorderTransfer::sole();
    $refund = $transfer->refundTransaction;

    expect($transfer->failure_reason)->toContain('refused')
        ->and($refund->direction)->toBe('debit')
        ->and($refund->refund_of_id)->toBe($transfer->funding_transaction_id)
        ->and((float) $refund->amount)->toBe(92.3)
        ->and($transfer->events->pluck('to_status')->map->value->all())->toBe(['awaiting_payment', 'funded', 'paying_out', 'payout_failed', 'refunding', 'refunded'])
        ->and(LedgerEntry::where('type', LedgerEntryType::REFUND->value)->where('account', 'provider')->value('amount_minor'))->toBe(-9230);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/payments/captures/CAPTURE-1/refund')
        && $request->hasHeader('PayPal-Request-Id', 'refund-'.$refund->transaction_id)
        && $request['amount'] === ['value' => '92.30', 'currency_code' => 'USD']);
});

test('a transfer to a pawapay failure test number triggers a paypal refund', function (string $number) {
    fakeCrossBorderPaypal();

    $reference = startTransfer(['account_number' => $number])->json('data.reference');

    verifyTransfer($reference)
        ->assertOk()
        ->assertJsonPath('status', 'refunded')
        ->assertJsonPath('data.refund.status', 'success');

    $transfer = CrossBorderTransfer::sole();
    expect($transfer->failure_reason)->toContain('refused');
})->with(['0763456079', '260763456079', '0973456089', '260973456089']);

test('a refused refund is flagged and can be retried with the same paypal request id', function () {
    fakeCrossBorderPaypal(['*/v2/payments/captures/CAPTURE-1/refund' => Http::sequence()
        ->push(['name' => 'UNPROCESSABLE_ENTITY', 'details' => [['issue' => 'REFUND_NOT_ALLOWED']]], 422)
        ->push(['id' => 'REFUND-1', 'status' => 'COMPLETED'], 201)]);

    $reference = startTransfer(['account_number' => '0977120000'])->json('data.reference');

    verifyTransfer($reference)->assertJsonPath('status', 'refund_failed');

    $refundId = CrossBorderTransfer::sole()->refund_transaction_id;

    verifyTransfer($reference)->assertJsonPath('status', 'refunded');

    expect(CrossBorderTransfer::sole()->refund_transaction_id)->toBe($refundId)
        ->and(Transaction::whereNotNull('refund_of_id')->count())->toBe(1);
});

test('a payment the sender never completes fails without paying anyone', function () {
    fakeCrossBorderPaypal(['*/v2/checkout/orders/ORDER-1' => Http::response(['name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID']]], 404)]);

    $reference = startTransfer()->json('data.reference');

    verifyTransfer($reference)->assertJsonPath('status', 'payment_failed');

    expect(CrossBorderTransfer::sole()->payout_transaction_id)->toBeNull()
        ->and(Transaction::where('direction', 'debit')->count())->toBe(0);
});

test('a funded transfer is only ever paid out once', function () {
    fakeCrossBorderPaypal();

    $reference = startTransfer()->json('data.reference');
    verifyTransfer($reference);

    $transfer = CrossBorderTransfer::sole();
    (new DisburseCrossBorderTransfer($transfer->id))->handle(app(CrossBorderTransferService::class));
    app(CrossBorderTransferService::class)->disburse($transfer);

    expect(Transaction::where('direction', 'debit')->count())->toBe(1);
});

test('an idempotency key replays the transfer instead of starting another', function () {
    fakeCrossBorderPaypal();

    $first = startTransfer(headers: ['Idempotency-Key' => 'send-mum-1'])->json('data.reference');

    startTransfer(headers: ['Idempotency-Key' => 'send-mum-1'])
        ->assertOk()
        ->assertJsonPath('data.reference', $first)
        ->assertJsonPath('data.replayed', true);

    expect(CrossBorderTransfer::count())->toBe(1);
    Http::assertSentCount(2); // one token, one order
});

test('a transfer uses only the account\'s own fx quote, in the right currencies', function () {
    fakeCrossBorderPaypal();
    $quoter = app(FxQuoter::class);

    $foreign = $quoter->quoteTarget('USD', 'ZMW', '2500', ownerId: User::factory()->create()->id);
    startTransfer(['fx_quote_id' => $foreign->id])->assertStatus(400);

    $wrongPair = $quoter->quoteTarget('USD', 'KES', '2500', ownerId: $this->user->id);
    startTransfer(['fx_quote_id' => $wrongPair->id])->assertStatus(422)->assertJsonPath('message', 'The FX quote is for USD to KES, not USD to ZMW.');

    $own = $quoter->quote('USD', 'ZMW', '50', ownerId: $this->user->id);
    startTransfer(['fx_quote_id' => $own->id])
        ->assertOk()
        ->assertJsonPath('data.sender_pays.amount', '50.00')
        ->assertJsonPath('data.fx.quote_id', $own->id);
});

test('a transfer is refused up front when no payout provider can deliver', function () {
    Http::fake();
    $this->wallets->update(['is_active' => false]);

    startTransfer()
        ->assertStatus(400)
        ->assertJsonPath('message', 'No active payout provider can pay ZMW in Zambia.');

    expect(CrossBorderTransfer::count())->toBe(0);
    Http::assertNothingSent();
});

test('another account cannot verify a transfer', function () {
    fakeCrossBorderPaypal();
    $reference = startTransfer()->json('data.reference');

    User::factory()->create(['api_token' => 'other-token']);

    $this->withToken('other-token')->postJson('/api/v1/transfer/verify', ['reference' => $reference])->assertNotFound();
});

test('paypal checkout only accepts an fx quote owned by the account', function () {
    Http::fake();
    $foreign = app(FxQuoter::class)->quote('ZMW', 'USD', '1000', ownerId: User::factory()->create()->id);

    $this->withToken('cb-token')->postJson('/api/v1/payment/request', [
        'amount' => 1000,
        'account_number' => 'payer@example.com',
        'country' => 'ZM',
        'fx_quote_id' => $foreign->id,
    ])->assertStatus(422);

    expect(Transaction::count())->toBe(0);
});

test('the transfer status labels describe each step', function () {
    expect(CrossBorderStatus::PAYOUT_FAILED->label())->toBe('Delivery failed, refunding')
        ->and(CrossBorderStatus::DELIVERED->isFinal())->toBeTrue()
        ->and(CrossBorderStatus::FUNDED->canTransitionTo(CrossBorderStatus::DELIVERED))->toBeFalse();
});

test('a transfer can carry the recipient\'s email, which must be valid', function () {
    startTransfer(['recipient_email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('recipient_email');

    fakeCrossBorderPaypal();
    startTransfer(['recipient_email' => 'mum@example.com'])->assertOk();

    expect(CrossBorderTransfer::query()->latest('id')->value('recipient_email'))->toBe('mum@example.com');
});
