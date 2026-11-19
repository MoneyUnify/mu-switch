<?php

use App\Enums\TransactionStatus;
use App\Jobs\ReverifyTransaction;
use App\Models\User;
use App\Support\TransactionVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(fn () => Sleep::fake());

test('reverify transaction job verifies the transaction with its provider', function () {
    $order = paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED');
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1' => Http::response($order),
    ]);

    $transaction = pendingPaypalTransaction(paypalProvider(User::factory()->create()), [
        'status' => TransactionStatus::SUCCESS,
        'provider_response' => ['order' => $order],
    ]);

    (new ReverifyTransaction($transaction))->handle(app(TransactionVerifier::class));

    Http::assertSent(fn ($req) => str_contains($req->url(), '/v2/checkout/orders/ORDER-1'));
});

test('reverify transaction job catches exceptions safely', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(null, 500),
    ]);

    $transaction = pendingPaypalTransaction(paypalProvider(User::factory()->create()), [
        'status' => TransactionStatus::SUCCESS,
        'provider_response' => ['order' => paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED')],
    ]);

    // Should not throw
    (new ReverifyTransaction($transaction))->handle(app(TransactionVerifier::class));

    expect(true)->toBeTrue();
});
