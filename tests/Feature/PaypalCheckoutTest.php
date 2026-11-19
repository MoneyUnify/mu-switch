<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\DpoController;
use App\Jobs\ReverifyTransaction;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

// PayPal calls retry with a pause; don't actually wait in tests.
beforeEach(fn () => Sleep::fake());

function paypalReturnUrl(Transaction $transaction, string $action = 'return'): string
{
    // PayPal appends its own token and PayerID to the signed URL.
    return URL::signedRoute("paypal.checkout.{$action}", ['transaction' => $transaction->transaction_id])
        .'&token=ORDER-1&PayerID=PAYER-9';
}

test('an approved payer is captured and sent back to the merchant', function () {
    $transaction = pendingPaypalTransaction(paypalProvider(User::factory()->create()), ['return_url' => 'https://shop.test/thanks?order=42']);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
    ]);

    $this->get(paypalReturnUrl($transaction))
        ->assertRedirect("https://shop.test/thanks?order=42&transaction_id={$transaction->transaction_id}&status=success");

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('without a return url the payer sees the outcome', function () {
    $transaction = pendingPaypalTransaction(paypalProvider(User::factory()->create()));

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
    ]);

    $this->get(paypalReturnUrl($transaction))
        ->assertOk()
        ->assertSee('Payment complete')
        ->assertSee('USD 25.00')
        ->assertSee('Verified with PayPal');
});

test('a payer who cancels fails the transaction', function () {
    $transaction = pendingPaypalTransaction(paypalProvider(User::factory()->create()), ['return_url' => 'https://shop.test/thanks']);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'PAYER_ACTION_REQUIRED')),
    ]);

    $this->get(paypalReturnUrl($transaction, 'cancel'))
        ->assertRedirect("https://shop.test/thanks?transaction_id={$transaction->transaction_id}&status=failed");

    $transaction->refresh();

    expect($transaction->status)->toBe(TransactionStatus::FAILED)
        ->and($transaction->provider_response['cancelled_by_payer'])->toBeTrue();
});

test('a tampered return link is rejected', function () {
    $transaction = pendingPaypalTransaction(paypalProvider(User::factory()->create()));

    Http::fake();

    $this->get("/checkout/paypal/{$transaction->transaction_id}/return?signature=forged")->assertForbidden();

    Http::assertNothingSent();
    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING);
});

test('return links only work for paypal transactions', function () {
    $user = User::factory()->create();
    $dpo = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'DPO',
        'class' => DpoController::class,
        'config' => ['company_token' => 't', 'service_type' => '1', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    $this->get(paypalReturnUrl(pendingPaypalTransaction($dpo)))->assertNotFound();
});

test('an already settled or final transaction renders immediately and dispatches background verification', function () {
    Queue::fake();
    Http::fake();
    Cache::flush();

    $order = paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED');
    $order['purchase_units'][0]['payments']['captures'][0] += [
        'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
        'create_time' => '2026-10-17T19:38:42Z',
    ];

    $transaction = pendingPaypalTransaction(paypalProvider(User::factory()->create()), [
        'status' => TransactionStatus::SUCCESS,
        'provider_response' => ['order' => $order],
    ]);

    $this->get(paypalReturnUrl($transaction))
        ->assertOk()
        ->assertSee('Payment complete')
        ->assertSee('USD 25.00');

    Http::assertNothingSent();
    Queue::assertPushed(ReverifyTransaction::class, fn (ReverifyTransaction $job) => $job->transaction->is($transaction));

    // A second visit within a minute uses cache and does not re-dispatch
    Queue::fake();
    $this->get(paypalReturnUrl($transaction))->assertOk();
    Queue::assertNotPushed(ReverifyTransaction::class);
});

test('an already refunded transaction renders refund outcome immediately without blocking', function () {
    Queue::fake();
    Http::fake();
    Cache::flush();

    $order = paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED');
    $order['purchase_units'][0]['payments']['captures'][0] += [
        'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
        'create_time' => '2026-10-17T19:38:42Z',
    ];

    $provider = paypalProvider(User::factory()->create());
    $transaction = pendingPaypalTransaction($provider, [
        'status' => TransactionStatus::SUCCESS,
        'provider_response' => ['order' => $order],
    ]);

    Transaction::create([
        'transaction_id' => (string) Str::uuid(),
        'provider_transaction_id' => 'REFUND-1',
        'customer_id' => $transaction->customer_id,
        'amount' => '25.00',
        'currency' => 'USD',
        'country' => $transaction->country,
        'status' => TransactionStatus::SUCCESS,
        'direction' => 'debit',
        'refund_of_id' => $transaction->id,
        'payment_provider_id' => $provider->id,
    ]);

    $this->get(paypalReturnUrl($transaction))
        ->assertOk()
        ->assertSee('Payment refunded')
        ->assertSee('USD 25.00');

    Http::assertNothingSent();
});
