<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\CgrateController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\ProviderLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function cgrateProvider(User $user): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'cGrate 543',
        'class' => CgrateController::class,
        'config' => ['username' => 'mu-merchant', 'password' => 'cg-secret', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
}

function konikEnvelope(int $code, string $message, ?string $paymentId = null): string
{
    $payment = $paymentId !== null ? "<paymentID>{$paymentId}</paymentID>" : '';

    return '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
        .'<ns2:processCustomerPaymentResponse xmlns:ns2="http://konik.cgrate.com">'
        ."<return><responseCode>{$code}</responseCode><responseMessage>{$message}</responseMessage>{$payment}</return>"
        .'</ns2:processCustomerPaymentResponse>'
        .'</soap:Body></soap:Envelope>';
}

test('the cgrate driver settles a synchronous payment as success', function () {
    $user = User::factory()->create(['api_token' => 'cgrate-token']);
    cgrateProvider($user);

    Http::fake([
        '*/Konik/KonikWs*' => Http::response(konikEnvelope(0, 'Payment successful', 'CG-PAY-123'), 200),
    ]);

    $this->withToken('cgrate-token')
        ->postJson('/api/v1/payment/request', ['amount' => 50, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        // processCustomerPayment is synchronous — code 0 means settled, not pending.
        ->assertJsonPath('data.status', 'success');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'CG-PAY-123',
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::SUCCESS->value,
    ]);

    // The SOAP envelope carries the operation, the WSSE username and the msisdn.
    Http::assertSent(function ($request) {
        $body = $request->body();

        return str_contains($request->url(), '/Konik/KonikWs')
            && str_contains($body, '<kon:processCustomerPayment>')
            && str_contains($body, '<customerMobile>260977123456</customerMobile>')
            && str_contains($body, '<transactionAmount>50.00</transactionAmount>')
            && str_contains($body, '<wsse:Username>mu-merchant</wsse:Username>');
    });
});

test('the cgrate driver keeps a delayed payment pending', function () {
    $user = User::factory()->create(['api_token' => 'cgrate-token-2']);
    cgrateProvider($user);

    Http::fake([
        '*/Konik/KonikWs*' => Http::response(konikEnvelope(8, 'Process delay'), 200),
    ]);

    $this->withToken('cgrate-token-2')
        ->postJson('/api/v1/payment/request', ['amount' => 50, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::PENDING->value]);
});

test('the cgrate driver surfaces a declined payment as an error', function () {
    $user = User::factory()->create(['api_token' => 'cgrate-token-3']);
    cgrateProvider($user);

    Http::fake([
        '*/Konik/KonikWs*' => Http::response(konikEnvelope(1, 'Insufficient balance'), 200),
    ]);

    $this->withToken('cgrate-token-3')
        ->postJson('/api/v1/payment/request', ['amount' => 50, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Insufficient balance');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});

test('the cgrate driver redacts the WSSE password from provider logs', function () {
    $user = User::factory()->create(['api_token' => 'cgrate-token-4']);
    cgrateProvider($user);

    Http::fake([
        '*/Konik/KonikWs*' => Http::response(konikEnvelope(0, 'OK', 'CG-1'), 200),
    ]);

    $this->withToken('cgrate-token-4')
        ->postJson('/api/v1/payment/request', ['amount' => 50, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk();

    $log = ProviderLog::where('url', 'like', '%KonikWs%')->firstOrFail();
    expect($log->request_body)->not->toContain('cg-secret');
    expect($log->request_body)->toContain('[REDACTED]');
    expect($log->request_body)->toContain('mu-merchant'); // the username stays visible
});

function pendingCgrateTransaction(User $user): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-cgrate@example.com']);

    return Transaction::create([
        'transaction_id' => 'MU-20260701-ABC123',
        'payment_provider_id' => cgrateProvider($user)->id,
        'provider_transaction_id' => 'MU-20260701-ABC123',
        'customer_id' => $customer->id,
        'amount' => 50,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
    ]);
}

test('the cgrate driver maps a successful query to success on verification', function () {
    $user = User::factory()->create(['api_token' => 'cgrate-verify']);
    $transaction = pendingCgrateTransaction($user);

    Http::fake([
        '*/Konik/KonikWs*' => Http::response(konikEnvelope(0, 'Payment successful', 'CG-PAY-77'), 200),
    ]);

    $this->withToken('cgrate-verify')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', '0');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
    expect($transaction->provider_transaction_id)->toBe('CG-PAY-77');

    // The query is sent by our paymentReference (the transaction_id).
    Http::assertSent(fn ($request) => str_contains($request->body(), '<kon:queryCustomerPayment>')
        && str_contains($request->body(), '<paymentReference>MU-20260701-ABC123</paymentReference>'));
});

test('the cgrate driver maps a failed query to failed on verification', function () {
    $user = User::factory()->create(['api_token' => 'cgrate-verify-2']);
    $transaction = pendingCgrateTransaction($user);

    Http::fake([
        '*/Konik/KonikWs*' => Http::response(konikEnvelope(5, 'Transaction cancelled'), 200),
    ]);

    $this->withToken('cgrate-verify-2')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});

test('the cgrate driver treats an unknown reference as a verification error, not a settled outcome', function () {
    $user = User::factory()->create(['api_token' => 'cgrate-verify-3']);
    $transaction = pendingCgrateTransaction($user);

    Http::fake([
        '*/Konik/KonikWs*' => Http::response(konikEnvelope(105, 'Transaction not found'), 200),
    ]);

    $this->withToken('cgrate-verify-3')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertStatus(404)
        ->assertJsonPath('status', 'error');

    // The stored status must remain untouched.
    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING);
});

test('the cgrate driver surfaces a SOAP fault as an error', function () {
    $user = User::factory()->create(['api_token' => 'cgrate-fault']);
    cgrateProvider($user);

    $fault = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault>'
        .'<faultcode>soap:Server</faultcode><faultstring>Authentication failed</faultstring>'
        .'</soap:Fault></soap:Body></soap:Envelope>';

    Http::fake(['*/Konik/KonikWs*' => Http::response($fault, 500)]);

    $this->withToken('cgrate-fault')
        ->postJson('/api/v1/payment/request', ['amount' => 50, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertStatus(502)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Authentication failed');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});
