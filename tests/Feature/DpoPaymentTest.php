<?php

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\DpoController;
use App\Models\Customer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function dpoProvider(User $user, array $countries = ['ZM']): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'DPO '.implode('', $countries),
        'class' => DpoController::class,
        'config' => [
            'company_token' => 'company-token-123',
            'service_type' => '5525',
            'mno_codes' => ['ZM' => 'AirtelZM', 'KE' => 'MPESA'],
            'supported_countries' => $countries,
        ],
        'is_active' => true,
    ]);
}

/**
 * Build a minimal DPO API3G XML response body.
 */
function dpoXml(array $tags): string
{
    $body = '';
    foreach ($tags as $tag => $value) {
        $body .= "<{$tag}>{$value}</{$tag}>";
    }

    return '<?xml version="1.0" encoding="utf-8"?><API3G>'.$body.'</API3G>';
}

test('the dpo driver creates a token then pushes the mobile money charge', function () {
    $user = User::factory()->create(['api_token' => 'dpo-user']);
    dpoProvider($user);

    Http::fake([
        '*3gdirectpay.com*' => Http::sequence()
            ->push(dpoXml(['Result' => '000', 'ResultExplanation' => 'Transaction created', 'TransToken' => 'ABC-TOKEN', 'TransRef' => '99887766']), 200)
            ->push(dpoXml(['Result' => '000', 'ResultExplanation' => 'Mobile payment initiated']), 200),
    ]);

    $this->withToken('dpo-user')
        ->postJson('/api/v1/payment/request', ['amount' => 100, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.status', 'pending');

    // The token from createToken becomes the reference we verify against.
    $this->assertDatabaseHas('transactions', [
        'provider_transaction_id' => 'ABC-TOKEN',
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING->value,
    ]);

    // Step 1 — createToken carries the merchant's service type and amount.
    Http::assertSent(fn ($request) => str_contains($request->body(), '<Request>createToken</Request>')
        && str_contains($request->body(), '<ServiceType>5525</ServiceType>')
        && str_contains($request->body(), '<PaymentAmount>100.00</PaymentAmount>')
        && str_contains($request->body(), '<PaymentCurrency>ZMW</PaymentCurrency>')
        && str_contains($request->body(), '<CompanyToken>company-token-123</CompanyToken>'));

    // Step 2 — ChargeTokenMobile pushes to the operator with the E.164 msisdn.
    Http::assertSent(fn ($request) => str_contains($request->body(), '<Request>ChargeTokenMobile</Request>')
        && str_contains($request->body(), '<TransactionToken>ABC-TOKEN</TransactionToken>')
        && str_contains($request->body(), '<MNO>AirtelZM</MNO>')
        && str_contains($request->body(), '<MNOcountry>zambia</MNOcountry>')
        && str_contains($request->body(), '<PhoneNumber>260977123456</PhoneNumber>'));
});

test('the dpo driver surfaces a createToken failure as an error', function () {
    $user = User::factory()->create(['api_token' => 'dpo-fail-1']);
    dpoProvider($user);

    Http::fake([
        '*3gdirectpay.com*' => Http::response(dpoXml(['Result' => '904', 'ResultExplanation' => 'Currency not supported']), 200),
    ]);

    $this->withToken('dpo-fail-1')
        ->postJson('/api/v1/payment/request', ['amount' => 100, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('message', 'Currency not supported');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});

test('the dpo driver surfaces a declined mobile charge as an error', function () {
    $user = User::factory()->create(['api_token' => 'dpo-fail-2']);
    dpoProvider($user);

    Http::fake([
        '*3gdirectpay.com*' => Http::sequence()
            ->push(dpoXml(['Result' => '000', 'TransToken' => 'TK-1']), 200)
            ->push(dpoXml(['Result' => '999', 'ResultExplanation' => 'Transaction Declined']), 200),
    ]);

    $this->withToken('dpo-fail-2')
        ->postJson('/api/v1/payment/request', ['amount' => 100, 'account_number' => '0977123456', 'country' => 'ZM'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Transaction Declined');

    $this->assertDatabaseHas('transactions', ['status' => TransactionStatus::FAILED->value]);
});

test('the dpo driver errors when no operator is configured for the market', function () {
    $user = User::factory()->create(['api_token' => 'dpo-no-mno']);
    // Rwanda is ticked but no MNO code was configured for it.
    dpoProvider($user, ['RW']);

    Http::fake();

    $this->withToken('dpo-no-mno')
        ->postJson('/api/v1/payment/request', ['amount' => 100, 'account_number' => '0788123456', 'country' => 'RW'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'error');

    Http::assertNothingSent();
});

function pendingDpoTransaction(User $user): Transaction
{
    $customer = Customer::create(['name' => 'Jane', 'email' => 'jane-dpo@example.com']);

    return Transaction::create([
        'transaction_id' => 'MU-dpo-ref-1',
        'payment_provider_id' => dpoProvider($user)->id,
        'provider_transaction_id' => 'ABC-TOKEN',
        'customer_id' => $customer->id,
        'amount' => 100,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING,
        'direction' => 'credit',
        'is_fx' => false,
    ]);
}

test('the dpo driver maps a paid verification to success', function () {
    $user = User::factory()->create(['api_token' => 'dpo-verify']);
    $transaction = pendingDpoTransaction($user);

    Http::fake([
        '*3gdirectpay.com*' => Http::response(dpoXml(['Result' => '000', 'ResultExplanation' => 'Transaction Paid', 'TransactionApproval' => '556677']), 200),
    ]);

    $this->withToken('dpo-verify')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.provider_status', '000');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::SUCCESS);

    // verifyToken is keyed on the DPO transaction token.
    Http::assertSent(fn ($request) => str_contains($request->body(), '<Request>verifyToken</Request>')
        && str_contains($request->body(), '<TransactionToken>ABC-TOKEN</TransactionToken>'));
});

test('the dpo driver keeps an unpaid verification pending', function () {
    $user = User::factory()->create(['api_token' => 'dpo-verify-2']);
    $transaction = pendingDpoTransaction($user);

    Http::fake([
        '*3gdirectpay.com*' => Http::response(dpoXml(['Result' => '900', 'ResultExplanation' => 'Transaction not paid yet']), 200),
    ]);

    $this->withToken('dpo-verify-2')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'pending');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::PENDING);
});

test('the dpo driver maps a cancelled verification to failed', function () {
    $user = User::factory()->create(['api_token' => 'dpo-verify-3']);
    $transaction = pendingDpoTransaction($user);

    Http::fake([
        '*3gdirectpay.com*' => Http::response(dpoXml(['Result' => '904', 'ResultExplanation' => 'Transaction cancelled']), 200),
    ]);

    $this->withToken('dpo-verify-3')
        ->postJson('/api/v1/payment/verify', ['transaction_id' => $transaction->transaction_id])
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($transaction->refresh()->status)->toBe(TransactionStatus::FAILED);
});
