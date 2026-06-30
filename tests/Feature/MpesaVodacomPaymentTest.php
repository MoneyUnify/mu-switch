<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\MpesaVodacomController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Cache::flush());

/**
 * Generate a real RSA public key (base64 body, no PEM headers) so the driver's
 * openssl encryption succeeds against it during the test.
 */
function vodacomPublicKey(): string
{
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $details = openssl_pkey_get_details($resource);

    return preg_replace('/-----[^-]+-----|\s+/', '', (string) $details['key']);
}

function vodacomProvider(User $user, string $country = 'TZ'): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'M-Pesa Vodacom '.$country,
        'class' => MpesaVodacomController::class,
        'config' => [
            'api_key' => 'app-api-key',
            'public_key' => vodacomPublicKey(),
            'service_provider_code' => '000000',
            'supported_countries' => [$country],
        ],
        'is_active' => true,
    ]);
}

function fakeVodacomSession(): array
{
    return ['*/getSession/' => Http::response(['output_ResponseCode' => 'INS-0', 'output_SessionID' => 'sess-123'], 200)];
}

test('the vodacom driver authenticates via session and initiates a c2b push', function () {
    $user = User::factory()->create(['api_token' => 'vodacom-token']);
    vodacomProvider($user, 'TZ');

    Http::fake([
        ...fakeVodacomSession(),
        '*/c2bPayment/singleStage/' => Http::response([
            'output_ResponseCode' => 'INS-0',
            'output_ResponseDesc' => 'Request processed successfully',
            'output_TransactionID' => 'MP-TXN-1',
            'output_ConversationID' => 'CONV-1',
        ], 200),
    ]);

    $this->withToken('vodacom-token')
        ->postJson('/api/v1/payment/request', ['amount' => 1500, 'account_number' => '0712345678', 'country' => 'TZ'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'MP-TXN-1',
        'currency' => 'TZS',
        'country' => 'TZ',
        'status' => TransactionStatus::PENDING->value,
    ]);

    // The session is fetched for the right market, then the push carries the
    // session bearer and the correct market country/currency/msisdn.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/openapi/ipg/v2/vodacomTZN/getSession/'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/openapi/ipg/v2/vodacomTZN/c2bPayment/singleStage/')
        && $request->hasHeader('Authorization')
        && $request['input_Country'] === 'TZN'
        && $request['input_Currency'] === 'TZS'
        && $request['input_CustomerMSISDN'] === '255712345678'
        && $request['input_ServiceProviderCode'] === '000000');
});

test('the vodacom driver surfaces a declined push as an error', function () {
    $user = User::factory()->create(['api_token' => 'vodacom-token-2']);
    vodacomProvider($user, 'TZ');

    Http::fake([
        ...fakeVodacomSession(),
        '*/c2bPayment/singleStage/' => Http::response([
            'output_ResponseCode' => 'INS-6',
            'output_ResponseDesc' => 'Transaction Failed',
        ], 422),
    ]);

    $this->withToken('vodacom-token-2')
        ->postJson('/api/v1/payment/request', ['amount' => 1500, 'account_number' => '0712345678', 'country' => 'TZ'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Transaction Failed');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});

test('the vodacom driver uses the correct market for Ghana', function () {
    $user = User::factory()->create(['api_token' => 'vodacom-gh']);
    vodacomProvider($user, 'GH');

    Http::fake([
        ...fakeVodacomSession(),
        '*/c2bPayment/singleStage/' => Http::response(['output_ResponseCode' => 'INS-0', 'output_TransactionID' => 'GH-1'], 200),
    ]);

    $this->withToken('vodacom-gh')
        ->postJson('/api/v1/payment/request', ['amount' => 50, 'account_number' => '0241234567', 'country' => 'GH'])
        ->assertOk();

    $this->assertDatabaseHas('transactions', ['currency' => 'GHS', 'country' => 'GH']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/openapi/ipg/v2/vodafoneGHA/c2bPayment/singleStage/')
        && $request['input_Country'] === 'GHA'
        && $request['input_Currency'] === 'GHS');
});

function pendingVodacomTransaction(User $user): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-vodacom@example.com']);

    return Transaction::create([
        'transaction_id' => 'vodacom-ref-1',
        'payment_provider_id' => vodacomProvider($user, 'TZ')->id,
        'provider_transaction_id' => 'MP-TXN-1',
        'customer_id' => $customer->id,
        'amount' => 1500,
        'currency' => 'TZS',
        'country' => 'TZ',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
    ]);
}

test('the vodacom driver maps a Completed status to success on verification', function () {
    $user = User::factory()->create(['api_token' => 'vodacom-verify']);
    $transaction = pendingVodacomTransaction($user);

    Http::fake([
        ...fakeVodacomSession(),
        '*/queryTransactionStatus/*' => Http::response([
            'output_ResponseCode' => 'INS-0',
            'output_ResponseTransactionStatus' => 'Completed',
        ], 200),
    ]);

    $this->withToken('vodacom-verify')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', 'Completed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);
});

test('the vodacom driver maps a Failed status to failed on verification', function () {
    $user = User::factory()->create(['api_token' => 'vodacom-verify-2']);
    $transaction = pendingVodacomTransaction($user);

    Http::fake([
        ...fakeVodacomSession(),
        '*/queryTransactionStatus/*' => Http::response([
            'output_ResponseCode' => 'INS-0',
            'output_ResponseTransactionStatus' => 'Failed',
        ], 200),
    ]);

    $this->withToken('vodacom-verify-2')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});
