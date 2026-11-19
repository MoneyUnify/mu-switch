<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\LencoController;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Paypal\PaypalReceipt;
use App\Support\TransactionVerifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    $this->user = User::factory()->create();
    $this->paypal = paypalProvider($this->user);
});

/**
 * A captured order as PayPal returns it, with the payer and capture details.
 *
 * @return array<string, mixed>
 */
function capturedOrder(array $refunds = []): array
{
    $order = paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED');
    $order['payment_source'] = ['paypal' => [
        'email_address' => 'john.doe@example.com',
        'account_status' => 'VERIFIED',
        'name' => ['given_name' => 'John', 'surname' => 'Doe'],
        'address' => ['country_code' => 'US'],
    ]];
    $order['purchase_units'][0]['payments']['captures'][0] += [
        'amount' => ['currency_code' => 'USD', 'value' => '25.00'],
        'create_time' => '2026-10-17T19:38:42Z',
        'seller_protection' => ['status' => 'ELIGIBLE'],
        'seller_receivable_breakdown' => ['paypal_fee' => ['currency_code' => 'USD', 'value' => '1.36']],
    ];

    if ($refunds !== []) {
        $order['purchase_units'][0]['payments']['refunds'] = $refunds;
    }

    return $order;
}

function capturedPayment(PaymentProvider $provider, array $order): Transaction
{
    return pendingPaypalTransaction($provider, ['status' => TransactionStatus::SUCCESS, 'provider_response' => ['order' => $order]]);
}

test('the payment complete page shows a paypal-verified receipt', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(capturedOrder(), 201),
    ]);
    $payment = pendingPaypalTransaction($this->paypal);

    // PayPal appends its own token and PayerID to our signed return link.
    $this->get(URL::signedRoute('paypal.checkout.return', ['transaction' => $payment->transaction_id]).'&token=ORDER-1&PayerID=P1')
        ->assertOk()
        ->assertSee('Payment complete')
        ->assertSee('Verified with PayPal')
        ->assertSee('CAPTURE-1')
        ->assertSee('ORDER-1')
        ->assertSee('John Doe')
        ->assertSee('j•••@example.com')
        ->assertSee('Verified PayPal account')
        ->assertSee('Seller protection')
        ->assertSee('Sandbox · test payment')
        ->assertSee('PayPal has also emailed your official receipt', false)
        ->assertSee('/receipts/paypal/'.$payment->transaction_id, false)
        ->assertDontSee('john.doe@example.com')
        ->assertDontSee('1.36'); // PayPal's fee is the merchant's business, not the payer's
});

test('the permanent receipt link is signed and re-checks paypal at most once a minute', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1' => Http::response(capturedOrder()),
    ]);
    $payment = capturedPayment($this->paypal, capturedOrder());

    $this->get(route('paypal.receipt', $payment->transaction_id))->assertForbidden();

    $url = URL::signedRoute('paypal.receipt', ['transaction' => $payment->transaction_id]);
    $this->get($url)->assertOk()->assertSee('CAPTURE-1')->assertSee('Completed');
    $this->get($url)->assertOk();

    Http::assertSentCount(2); // one token and one order lookup, then cached
    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && str_ends_with($request->url(), '/v2/checkout/orders/ORDER-1'));
});

test('refunds made in paypal show on the receipt', function () {
    $refunded = capturedOrder([['id' => 'REFUND-1', 'status' => 'COMPLETED', 'amount' => ['value' => '10.00', 'currency_code' => 'USD']]]);
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1' => Http::response($refunded),
    ]);
    $payment = capturedPayment($this->paypal, capturedOrder());

    $receipt = PaypalReceipt::for($payment);

    expect($receipt['status'])->toBe('Partially refunded')
        ->and($receipt['refunded'])->toBe('10.00')
        ->and($receipt['checked_live'])->toBeTrue()
        ->and($receipt['paypal_transaction_id'])->toBe('CAPTURE-1')
        ->and($receipt['seller_protection'])->toBe('Eligible');
});

test('only captured paypal payments have a paypal receipt', function () {
    Http::fake();
    $lenco = PaymentProvider::create(['user_id' => $this->user->id, 'name' => 'Lenco', 'class' => LencoController::class, 'config' => ['api_key' => 'k'], 'is_active' => true]);
    $mobile = pendingPaypalTransaction($lenco, ['provider_transaction_id' => 'L-1', 'status' => TransactionStatus::SUCCESS]);

    expect(PaypalReceipt::for($mobile))->toBeNull()
        ->and(PaypalReceipt::for(pendingPaypalTransaction($this->paypal, ['provider_transaction_id' => 'ORDER-2']), refresh: false))->toBeNull();

    $this->get(URL::signedRoute('paypal.receipt', ['transaction' => $mobile->transaction_id]))->assertNotFound();
});

test('a refunded payment is presented as refunded, not complete', function () {
    $refunded = capturedOrder([['id' => 'REFUND-1', 'status' => 'COMPLETED', 'amount' => ['value' => '25.00', 'currency_code' => 'USD']]]);
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1' => Http::response($refunded),
    ]);
    $payment = capturedPayment($this->paypal, capturedOrder());

    $this->get(URL::signedRoute('paypal.receipt', ['transaction' => $payment->transaction_id]))
        ->assertOk()
        ->assertSee('Payment refunded')
        ->assertSee('This payment was refunded in full to your PayPal account.')
        ->assertDontSee('Payment complete');
});

test('a failed paypal lookup never wipes a settled payment\'s record', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1' => Http::response(['name' => 'RESOURCE_NOT_FOUND', 'details' => [['issue' => 'INVALID_RESOURCE_ID']], 'message' => 'The specified resource does not exist.'], 404),
    ]);
    $payment = capturedPayment($this->paypal, capturedOrder());

    $receipt = PaypalReceipt::for($payment);
    $payment->refresh();

    expect($payment->status)->toBe(TransactionStatus::SUCCESS)
        ->and(data_get($payment->provider_response, 'order.purchase_units.0.payments.captures.0.id'))->toBe('CAPTURE-1')
        ->and(data_get($payment->provider_response, 'last_sync_error.message'))->not->toBeNull()
        ->and($receipt['paypal_transaction_id'])->toBe('CAPTURE-1')
        ->and($receipt['payer']['name'])->toBe('John Doe');
});

test('a pending order paypal no longer knows still fails', function () {
    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1' => Http::response(['name' => 'RESOURCE_NOT_FOUND', 'message' => 'The specified resource does not exist.'], 404),
    ]);
    $payment = pendingPaypalTransaction($this->paypal);

    app(TransactionVerifier::class)->verify($payment);

    expect($payment->refresh()->status)->toBe(TransactionStatus::FAILED)
        ->and(data_get($payment->provider_response, 'order.error'))->not->toBeNull();
});
