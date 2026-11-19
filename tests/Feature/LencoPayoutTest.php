<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\LencoController;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\CrossBorderTransfer;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use App\Support\DriverRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const LENCO_ACCOUNT = 'b176cda5-7d97-4a3f-b4dd-ab0234e9e08c';
const LENCO_RECIPIENT_ID = 'd6b6e00e-bdb6-43a6-a561-85b61496198e';

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    $this->user = User::factory()->create(['api_token' => 'lenco-payout-token']);
});

function lencoPayouts(User $user, array $config = []): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco',
        'class' => LencoController::class,
        'config' => ['api_key' => 'lenco-key', 'account_id' => LENCO_ACCOUNT, 'supported_countries' => ['ZM', 'MW'], ...$config],
        'is_active' => true,
    ]);
}

/**
 * Lenco's transfer recipient object, as returned by /transfer-recipients/mobile-money.
 *
 * @return array<string, mixed>
 */
function lencoRecipient(string $id = LENCO_RECIPIENT_ID, string $phone = '0977123456', string $operator = 'airtel', string $country = 'zm'): array
{
    return [
        'status' => true,
        'message' => '',
        'data' => [
            'id' => $id,
            'details' => [
                'type' => 'mobile-money',
                'accountName' => 'Beata Jean',
                'phone' => $phone,
                'operator' => $operator,
            ],
            'currency' => strtoupper($country) === 'MW' ? 'MWK' : 'ZMW',
            'type' => 'mobile-money',
            'country' => strtolower($country),
        ],
    ];
}

/**
 * Lenco's transfer object, as returned by its transfer endpoints.
 *
 * @return array<string, mixed>
 */
function lencoTransfer(string $status = 'pending', ?string $reason = null): array
{
    return [
        'status' => true,
        'message' => '',
        'data' => [
            'id' => '9525b4c6-502b-45be-90e1-81eb81a3f424',
            'amount' => '250.00',
            'fee' => '8.50',
            'currency' => 'ZMW',
            'status' => $status,
            'reasonForFailure' => $reason,
            'reference' => 'ref',
            'lencoReference' => '240010002',
        ],
    ];
}

function lencoPayout(array $body = [])
{
    return test()->withToken('lenco-payout-token')->postJson('/api/v1/payout/request', [
        'amount' => 250,
        'account_number' => '0977123456',
        'country' => 'ZM',
        ...$body,
    ]);
}

test('lenco is offered as a payout driver', function () {
    expect(DriverRegistry::capabilities(LencoController::class))->toContain('payouts');
});

test('a lenco payout sends a mobile-money transfer from the configured account', function () {
    lencoPayouts($this->user);
    Http::fake([
        '*/transfer-recipients/mobile-money' => Http::response(lencoRecipient()),
        '*/transfers/mobile-money' => Http::response(lencoTransfer()),
    ]);

    $response = lencoPayout(['narration' => 'School fees'])
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $transaction = Transaction::where('transaction_id', $response->json('data.transaction_id'))->sole();
    expect($transaction->direction)->toBe('debit')
        ->and($transaction->provider_transaction_id)->toBe('9525b4c6-502b-45be-90e1-81eb81a3f424');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/access/v2/transfer-recipients/mobile-money')
        && $request->hasHeader('Authorization', 'Bearer lenco-key')
        && $request['phone'] === '0977123456'
        && $request['operator'] === 'airtel'
        && $request['country'] === 'zm');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/access/v2/transfers/mobile-money')
        && $request->hasHeader('Authorization', 'Bearer lenco-key')
        && $request['accountId'] === LENCO_ACCOUNT
        && $request['amount'] == 250
        && $request['reference'] === $transaction->transaction_id
        && $request['transferRecipientId'] === LENCO_RECIPIENT_ID
        && $request['narration'] === 'School fees');
});

test('verifying a lenco payout reads its status by our reference', function () {
    lencoPayouts($this->user);
    Http::fake([
        '*/transfer-recipients/mobile-money' => Http::response(lencoRecipient()),
        '*/transfers/mobile-money' => Http::response(lencoTransfer()),
        '*/transfers/status/*' => Http::response(lencoTransfer('successful')),
    ]);

    $id = lencoPayout()->json('data.transaction_id');

    $this->withToken('lenco-payout-token')
        ->postJson('/api/v1/payout/verify', ['transaction_id' => $id])
        ->assertOk()
        ->assertJsonPath('data.status', 'success')
        ->assertJsonPath('data.provider_status', 'successful');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), "/transfers/status/{$id}"));
});

test('a transfer lenco never created is marked failed on verify', function () {
    lencoPayouts($this->user);
    Http::fake([
        '*/transfer-recipients/mobile-money' => Http::response(lencoRecipient()),
        '*/transfers/mobile-money' => Http::response(['status' => false, 'message' => 'Service unavailable', 'data' => null], 503),
        '*/transfers/status/*' => Http::response(['status' => false, 'message' => 'Transfer was not found', 'data' => null], 404),
    ]);

    $id = lencoPayout()->assertStatus(202)->json('data.transaction_id');

    $this->withToken('lenco-payout-token')
        ->postJson('/api/v1/payout/verify', ['transaction_id' => $id])
        ->assertJsonPath('data.status', 'failed');
});

test('a lenco refusal falls back to the next payout provider', function () {
    lencoPayouts($this->user);
    PaymentProvider::create([
        'user_id' => $this->user->id,
        'name' => 'Simulated',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
    Http::fake([
        '*/transfer-recipients/mobile-money' => Http::response(lencoRecipient()),
        '*/transfers/mobile-money' => Http::response(['status' => false, 'message' => 'Insufficient balance', 'data' => null], 400),
    ]);

    lencoPayout()->assertOk()->assertJsonPath('data.status', 'pending');

    expect(Transaction::where('direction', 'debit')->orderBy('id')->pluck('status')->map->value->all())->toBe(['failed', 'pending'])
        ->and(Transaction::latest('id')->first()->paymentProvider->class)->toBe(SandboxPayoutController::class);
});

test('a lenco recipient verification failure falls back to the next payout provider', function () {
    lencoPayouts($this->user);
    PaymentProvider::create([
        'user_id' => $this->user->id,
        'name' => 'Simulated',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
    Http::fake([
        '*/transfer-recipients/mobile-money' => Http::response(['status' => false, 'message' => 'Account Details could not be verified', 'data' => null], 400),
    ]);

    lencoPayout()->assertOk()->assertJsonPath('data.status', 'pending');

    expect(Transaction::where('direction', 'debit')->orderBy('id')->pluck('status')->map->value->all())->toBe(['failed', 'pending'])
        ->and(Transaction::latest('id')->first()->paymentProvider->class)->toBe(SandboxPayoutController::class);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/transfers/mobile-money'));
});

test('an unknown lenco outcome stops without trying another provider', function (int $status, string $message) {
    lencoPayouts($this->user);
    PaymentProvider::create([
        'user_id' => $this->user->id,
        'name' => 'Simulated',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
    Http::fake([
        '*/transfer-recipients/mobile-money' => Http::response(lencoRecipient()),
        '*/transfers/mobile-money' => Http::response(['status' => false, 'message' => $message, 'data' => null], $status),
    ]);

    lencoPayout()->assertStatus(202)->assertJsonPath('status', 'pending');

    expect(Transaction::where('direction', 'debit')->count())->toBe(1);
})->with([
    'server error' => [500, 'Internal error'],
    'duplicate reference' => [400, 'Duplicate reference'],
]);

test('a server error during recipient creation stops without trying another provider', function () {
    lencoPayouts($this->user);
    PaymentProvider::create([
        'user_id' => $this->user->id,
        'name' => 'Simulated',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
    Http::fake([
        '*/transfer-recipients/mobile-money' => Http::response(['status' => false, 'message' => 'Internal server error', 'data' => null], 500),
    ]);

    lencoPayout()->assertStatus(202)->assertJsonPath('status', 'pending');

    expect(Transaction::where('direction', 'debit')->count())->toBe(1);
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/transfers/mobile-money'));
});

test('lenco is ready for payout routing with just its api key', function () {
    lencoPayouts($this->user, ['account_id' => null]);
    expect(LencoController::payoutReady(['api_key' => 'lenco-key']))->toBeTrue();
});

test('lenco is left out of payout routing if api key is missing', function () {
    lencoPayouts($this->user, ['api_key' => '']);
    Http::fake();

    lencoPayout()
        ->assertStatus(400)
        ->assertJsonPath('message', 'No active payout providers support the requested country');

    Http::assertNothingSent();
});

test('lenco auto-discovers debit account from /accounts when not configured', function () {
    lencoPayouts($this->user, ['account_id' => null]);
    Http::fake([
        '*/accounts' => Http::response([
            'status' => true,
            'message' => '',
            'data' => [
                ['id' => 'auto-discovered-account-id', 'currency' => 'ZMW'],
            ],
        ]),
        '*/transfer-recipients/mobile-money' => Http::response(lencoRecipient()),
        '*/transfers/mobile-money' => Http::response(lencoTransfer()),
    ]);

    lencoPayout()->assertOk();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/access/v2/accounts'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transfers/mobile-money')
        && $request['accountId'] === 'auto-discovered-account-id');
});

test('the lenco driver refuses a payout if debit account cannot be resolved', function () {
    $provider = lencoPayouts($this->user, ['account_id' => null]);
    $driver = app(LencoController::class);
    $driver->setProvider($provider);

    $transaction = Transaction::create([
        'transaction_id' => 'no-account',
        'provider_transaction_id' => 'no-account',
        'payment_provider_id' => $provider->id,
        'customer_id' => Customer::create(['name' => 'R', 'email' => 'r@moneyunify.local'])->id,
        'amount' => 10,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING,
        'direction' => 'debit',
    ]);

    Http::fake([
        '*/accounts' => Http::response(['status' => false, 'message' => 'No accounts found', 'data' => []], 404),
    ]);

    expect($driver->requestPayout($transaction, ['account_number' => '0977123456'])->getStatusCode())->toBe(422)
        ->and($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});

test('lenco needs the network for malawi numbers it cannot place', function () {
    lencoPayouts($this->user);
    Http::fake([
        '*/transfer-recipients/mobile-money' => Http::response(lencoRecipient(phone: '0897123456', operator: 'tnm', country: 'mw')),
        '*/transfers/mobile-money' => Http::response(lencoTransfer()),
    ]);

    lencoPayout(['country' => 'MW', 'currency' => 'MWK', 'account_number' => '0897123456'])
        ->assertStatus(422)
        ->assertJsonPath('message', "Lenco can't tell this number's mobile network in Malawi. Send `network` (airtel, tnm).");

    lencoPayout(['country' => 'MW', 'currency' => 'MWK', 'account_number' => '0897123456', 'network' => 'TNM'])->assertOk();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transfer-recipients/mobile-money')
        && $request['operator'] === 'tnm' && $request['country'] === 'mw' && $request['phone'] === '0897123456');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transfers/mobile-money')
        && $request['transferRecipientId'] === LENCO_RECIPIENT_ID);
});

test('a lenco payout uses an explicit transfer recipient id when provided', function () {
    $provider = lencoPayouts($this->user);
    $driver = app(LencoController::class);
    $driver->setProvider($provider);

    $transaction = Transaction::create([
        'transaction_id' => 'explicit-recipient',
        'provider_transaction_id' => 'explicit-recipient',
        'payment_provider_id' => $provider->id,
        'customer_id' => Customer::create(['name' => 'R', 'email' => 'r@moneyunify.local'])->id,
        'amount' => 10,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING,
        'direction' => 'debit',
    ]);

    Http::fake([
        '*/transfers/mobile-money' => Http::response(lencoTransfer()),
    ]);

    $response = $driver->requestPayout($transaction, [
        'account_number' => '0977123456',
        'transfer_recipient_id' => 'custom-recipient-id',
    ]);

    expect($response->getStatusCode())->toBe(200);

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/transfer-recipients/mobile-money'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transfers/mobile-money')
        && $request['transferRecipientId'] === 'custom-recipient-id');
});

test('a cross-border transfer can be delivered through lenco', function () {
    paypalProvider($this->user);
    lencoPayouts($this->user);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
        '*/v2/checkout/orders' => Http::response(paypalOrder(), 201),
        '*/transfer-recipients/mobile-money' => Http::response(lencoRecipient(phone: '0961234567', operator: 'mtn')),
        '*/transfers/mobile-money' => Http::response(lencoTransfer()),
        '*/transfers/status/*' => Http::response(lencoTransfer('successful')),
    ]);

    $reference = $this->withToken('lenco-payout-token')->postJson('/api/v1/transfer/request', [
        'amount' => 2500,
        'country' => 'ZM',
        'account_number' => '0961234567',
    ])->assertOk()->assertJsonPath('data.recipient_gets.amount', '2500.00')->json('data.reference');

    $verify = fn () => $this->withToken('lenco-payout-token')->postJson('/api/v1/transfer/verify', ['reference' => $reference]);

    $verify()->assertJsonPath('status', 'paying_out')->assertJsonPath('data.payout.provider', 'Lenco');
    $verify()->assertJsonPath('status', 'delivered');

    $payout = CrossBorderTransfer::sole()->payoutTransaction;
    expect($payout->status)->toBe(TransactionStatus::SUCCESS)
        ->and($payout->is_fx)->toBeTruthy();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transfer-recipients/mobile-money')
        && $request['operator'] === 'mtn' && $request['phone'] === '0961234567');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/transfers/mobile-money')
        && $request['amount'] == 2500 && $request['transferRecipientId'] === LENCO_RECIPIENT_ID);
});
