<?php

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\LencoController;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\LedgerBalance;
use App\Support\Ledger\LedgerRecorder;
use Illuminate\Support\Str;

function lencoLedgerProvider(User $user, array $config = []): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Lenco '.Str::random(4),
        'class' => LencoController::class,
        'config' => ['api_key' => 'k', 'supported_countries' => ['ZM'], ...$config],
        'is_active' => true,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function ledgerTransaction(PaymentProvider $provider, array $attributes = []): Transaction
{
    $id = (string) Str::uuid();

    return Transaction::create([
        'transaction_id' => $id,
        'payment_provider_id' => $provider->id,
        'provider_transaction_id' => $id,
        'customer_id' => Customer::firstOrCreate(['email' => 'ledger@example.com'], ['name' => 'Ledger'])->id,
        'amount' => 750,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::SUCCESS,
        'direction' => 'credit',
        ...$attributes,
    ]);
}

/**
 * @return array<string, int> "type:account" => minor units
 */
function ledgerFor(Transaction $transaction): array
{
    return LedgerEntry::query()
        ->where('source_type', $transaction->getMorphClass())
        ->where('source_id', $transaction->id)
        ->get()
        ->mapWithKeys(fn (LedgerEntry $entry) => [$entry->type->value.':'.$entry->account->value => $entry->amount_minor])
        ->sortKeys()
        ->all();
}

test('a settled collection writes balanced entries for the amount and its fees', function () {
    $provider = lencoLedgerProvider(User::factory()->create());

    // Lenco: 1% + K8.50 settlement on K750 = K16.00 in fees.
    $transaction = ledgerTransaction($provider);

    expect(ledgerFor($transaction))->toBe([
        'collection:counterparty' => -75000,
        'collection:provider' => 75000,
        'fee:fees' => 1600,
        'fee:provider' => -1600,
    ])->and(array_sum(ledgerFor($transaction)))->toBe(0)
        ->and((string) LedgerBalance::of($provider, 'ZMW'))->toBe('734.00');
});

test('nothing is recorded until the transaction settles, and re-saving never duplicates', function () {
    $provider = lencoLedgerProvider(User::factory()->create());
    $transaction = ledgerTransaction($provider, ['status' => TransactionStatus::PENDING]);

    expect(ledgerFor($transaction))->toBe([]);

    $transaction->update(['status' => TransactionStatus::SUCCESS]);
    $transaction->touch();
    $transaction->save();

    expect(LedgerEntry::count())->toBe(4);
});

test('a settlement that is undone removes its entries', function () {
    $provider = lencoLedgerProvider(User::factory()->create());
    $transaction = ledgerTransaction($provider);

    $transaction->update(['status' => TransactionStatus::FAILED]);

    expect(ledgerFor($transaction))->toBe([])
        ->and((string) LedgerBalance::of($provider, 'ZMW'))->toBe('0.00');
});

test('paypal\'s actual fee replaces the estimated fee entries', function () {
    $provider = paypalProvider(User::factory()->create());
    $transaction = pendingPaypalTransaction($provider, ['amount' => 100, 'status' => TransactionStatus::SUCCESS]);

    $estimated = LedgerEntry::where('type', LedgerEntryType::FEE->value)->where('account', LedgerAccount::PROVIDER->value)->sole();
    expect($estimated->amount_minor)->toBe(-398)->and($estimated->estimated)->toBeTrue();

    $captured = paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED');
    $captured['purchase_units'][0]['payments']['captures'][0]['seller_receivable_breakdown']['paypal_fee'] = ['currency_code' => 'USD', 'value' => '3.70'];
    $transaction->update(['provider_response' => ['order' => $captured]]);

    $actual = $estimated->refresh();

    expect($actual->amount_minor)->toBe(-370)
        ->and($actual->estimated)->toBeFalse()
        ->and(LedgerEntry::count())->toBe(4)
        ->and((string) LedgerBalance::of($provider, 'USD'))->toBe('96.30');
});

test('a payout takes the amount and its fee out of the provider account', function () {
    $user = User::factory()->create();
    $provider = PaymentProvider::create([
        'user_id' => $user->id,
        'name' => 'Simulated',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
    app(LedgerRecorder::class)->recordOpeningBalance($provider, 'ZMW', '1000');

    // 1% payout fee on K200.
    $payout = ledgerTransaction($provider, ['amount' => 200, 'direction' => 'debit']);

    expect(ledgerFor($payout))->toBe([
        'fee:fees' => 200,
        'fee:provider' => -200,
        'payout:counterparty' => 20000,
        'payout:provider' => -20000,
    ])->and((string) LedgerBalance::of($provider, 'ZMW'))->toBe('798.00');
});

test('zero-decimal currencies are recorded in whole units', function () {
    $provider = lencoLedgerProvider(User::factory()->create(), [
        'fee_schedule' => ['collection' => ['percent' => 2.5]],
    ]);

    $transaction = ledgerTransaction($provider, ['amount' => 1001, 'currency' => 'XOF']);

    expect(ledgerFor($transaction))->toMatchArray(['collection:provider' => 1001, 'fee:provider' => -25])
        ->and((string) LedgerBalance::of($provider, 'XOF'))->toBe('976');
});

test('every event in a busy ledger sums to zero', function () {
    $provider = lencoLedgerProvider(User::factory()->create());

    foreach (['10.01', '99.99', '750', '1234.56', '0.05'] as $amount) {
        ledgerTransaction($provider, ['amount' => $amount]);
    }

    $unbalanced = LedgerEntry::query()
        ->selectRaw('source_id, sum(amount_minor) as total')
        ->groupBy('source_id')
        ->get()
        ->filter(fn ($row) => (int) $row->total !== 0);

    expect($unbalanced)->toBeEmpty();
});

test('balance summaries total payments in, fees and payouts out, per currency', function () {
    $provider = lencoLedgerProvider(User::factory()->create());
    ledgerTransaction($provider, ['amount' => 750]);
    ledgerTransaction($provider, ['amount' => 500]);
    ledgerTransaction($provider, ['amount' => 300, 'status' => TransactionStatus::PENDING]);

    expect(LedgerBalance::summaries([$provider->id])->get($provider->id))->toBe([[
        'currency' => 'ZMW',
        'count' => 2,
        'inflow' => 1250.0,
        // K16.00 + K13.50 in fees.
        'outflow' => 29.5,
        'net' => 1220.5,
        'estimated' => true,
    ]]);
});

test('the opening balance command records money already at a provider', function () {
    $provider = lencoLedgerProvider(User::factory()->create());

    $this->artisan('ledger:opening-balance', ['provider' => $provider->id, 'currency' => 'zmw', 'amount' => '5000', '--note' => 'Float on go-live'])
        ->assertSuccessful();

    $entry = LedgerEntry::where('account', LedgerAccount::PROVIDER->value)->sole();

    expect($entry->type)->toBe(LedgerEntryType::OPENING_BALANCE)
        ->and($entry->meta)->toBe(['note' => 'Float on go-live'])
        ->and((string) LedgerBalance::of($provider, 'ZMW'))->toBe('5000.00')
        ->and(LedgerEntry::sum('amount_minor'))->toBe(0);
});

test('the opening balance command rejects bad input', function (array $arguments) {
    $provider = lencoLedgerProvider(User::factory()->create());

    $this->artisan('ledger:opening-balance', ['provider' => $provider->id, ...$arguments])->assertFailed();

    expect(LedgerEntry::count())->toBe(0);
})->with([
    'negative amount' => [['currency' => 'ZMW', 'amount' => '-10']],
    'bad currency' => [['currency' => 'ZM', 'amount' => '10']],
    'unknown provider' => [['provider' => 999999, 'currency' => 'ZMW', 'amount' => '10']],
]);

test('rebuilding the ledger backfills past transactions and is safe to repeat', function () {
    $provider = lencoLedgerProvider(User::factory()->create());
    ledgerTransaction($provider, ['amount' => 750]);
    ledgerTransaction($provider, ['amount' => 500]);

    LedgerEntry::query()->delete();

    $this->artisan('ledger:rebuild')->assertSuccessful();
    $this->artisan('ledger:rebuild')->assertSuccessful();

    expect(LedgerEntry::count())->toBe(8)
        ->and((string) LedgerBalance::of($provider, 'ZMW'))->toBe('1220.50');
});
