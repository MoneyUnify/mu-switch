<?php

use App\Ai\Agents\TreasuryAgent;
use App\Enums\TransactionStatus;
use App\Enums\TransferStatus;
use App\Http\Controllers\Providers\LencoController;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\Customer;
use App\Models\FloatAlert;
use App\Models\FloatRule;
use App\Models\LedgerEntry;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Support\Ledger\LedgerBalance;
use App\Support\Ledger\LedgerRecorder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function treasuryAccount(User $user, string $name, string $class = LencoController::class): PaymentProvider
{
    return PaymentProvider::create([
        'user_id' => $user->id,
        'name' => $name,
        'class' => $class,
        'config' => ['api_key' => 'k', 'supported_countries' => ['ZM']],
        'is_active' => true,
    ]);
}

function fund(PaymentProvider $provider, string $amount, string $currency = 'ZMW'): void
{
    app(LedgerRecorder::class)->recordOpeningBalance($provider, $currency, $amount);
}

function floatRule(PaymentProvider $provider, int $minimumMinor, ?int $targetMinor = null): FloatRule
{
    return FloatRule::create([
        'user_id' => $provider->user_id,
        'payment_provider_id' => $provider->id,
        'currency' => 'ZMW',
        'minimum_minor' => $minimumMinor,
        'target_minor' => $targetMinor,
    ]);
}

function settledPayout(PaymentProvider $provider, int $amount): Transaction
{
    $id = (string) Str::uuid();

    return Transaction::create([
        'transaction_id' => $id,
        'payment_provider_id' => $provider->id,
        'provider_transaction_id' => $id,
        'customer_id' => Customer::firstOrCreate(['email' => 'treasury@example.com'], ['name' => 'Recipient'])->id,
        'amount' => $amount,
        'currency' => 'ZMW',
        'country' => 'ZM',
        'status' => TransactionStatus::SUCCESS,
        'direction' => 'debit',
    ]);
}

function requestTransfer(User $user, PaymentProvider $from, PaymentProvider $to, array $body = [])
{
    return test()->actingAs($user)->post(route('treasury.transfers.store'), [
        'from_provider_id' => $from->id,
        'to_provider_id' => $to->id,
        'currency' => 'ZMW',
        'amount' => '300',
        ...$body,
    ]);
}

// Float rules and alerts

test('a float dropping below its minimum opens one alert, which resolves when it recovers', function () {
    $user = User::factory()->create();
    $float = treasuryAccount($user, 'Payout float', SandboxPayoutController::class);
    fund($float, '1000');
    floatRule($float, 50000);

    // Two payouts take it below K500: still one open alert, with the latest balance.
    settledPayout($float, 400);
    settledPayout($float, 200);

    $alert = FloatAlert::sole();
    expect($alert->resolved_at)->toBeNull()
        // K1,000 − K400 − K4 fee − K200 − K2 fee.
        ->and($alert->balance_minor)->toBe(39400);

    fund($float, '500');

    expect($alert->refresh()->resolved_at)->not->toBeNull()
        ->and(FloatAlert::count())->toBe(1);
});

test('an inactive rule never alerts', function () {
    $user = User::factory()->create();
    $float = treasuryAccount($user, 'Float');
    FloatRule::create(['user_id' => $user->id, 'payment_provider_id' => $float->id, 'currency' => 'ZMW', 'minimum_minor' => 100000, 'is_active' => false]);

    $this->artisan('treasury:check-floats')->assertSuccessful();

    expect(FloatAlert::count())->toBe(0);
});

test('setting a float rule checks it straight away', function () {
    $user = User::factory()->create();
    $float = treasuryAccount($user, 'Float');
    fund($float, '200');

    $this->actingAs($user)
        ->put(route('treasury.float-rules.upsert'), ['provider_id' => $float->id, 'currency' => 'zmw', 'minimum' => '500', 'target' => '2000'])
        ->assertRedirect(route('treasury.index'));

    $rule = FloatRule::sole();
    expect($rule->minimum_minor)->toBe(50000)
        ->and($rule->target_minor)->toBe(200000)
        ->and($rule->openAlert)->not->toBeNull();
});

test('float rules can\'t be set on another account\'s provider or with a target below the minimum', function () {
    $user = User::factory()->create();
    $theirs = treasuryAccount(User::factory()->create(), 'Theirs');
    $mine = treasuryAccount($user, 'Mine');

    $this->actingAs($user)
        ->put(route('treasury.float-rules.upsert'), ['provider_id' => $theirs->id, 'currency' => 'ZMW', 'minimum' => '10'])
        ->assertSessionHasErrors('provider_id');

    $this->actingAs($user)
        ->put(route('treasury.float-rules.upsert'), ['provider_id' => $mine->id, 'currency' => 'ZMW', 'minimum' => '100', 'target' => '50'])
        ->assertSessionHasErrors('target');
});

// Transfers

test('a transfer moves money only once approved, sent and settled', function () {
    $user = User::factory()->create();
    $source = treasuryAccount($user, 'Collections');
    $float = treasuryAccount($user, 'Payout float', SandboxPayoutController::class);
    fund($source, '1000');

    requestTransfer($user, $source, $float, ['fee' => '5', 'note' => 'Top up float'])->assertRedirect(route('treasury.index'));
    $transfer = TreasuryTransfer::sole();

    expect($transfer->status)->toBe(TransferStatus::REQUESTED)
        ->and((string) LedgerBalance::of($source, 'ZMW'))->toBe('1000.00');

    $this->actingAs($user)->post(route('treasury.transfers.approve', $transfer));
    expect((string) LedgerBalance::of($source, 'ZMW'))->toBe('1000.00');

    $this->actingAs($user)->post(route('treasury.transfers.send', $transfer), ['external_reference' => 'BANK-123']);

    // Sent: out of the source (plus the fee), not yet in the float.
    expect((string) LedgerBalance::of($source, 'ZMW'))->toBe('695.00')
        ->and((string) LedgerBalance::of($float, 'ZMW'))->toBe('0.00');

    $this->actingAs($user)->post(route('treasury.transfers.settle', $transfer));

    expect((string) LedgerBalance::of($float, 'ZMW'))->toBe('300.00')
        ->and($transfer->refresh()->status)->toBe(TransferStatus::SETTLED)
        ->and($transfer->external_reference)->toBe('BANK-123')
        ->and($transfer->events->pluck('to_status')->map->value->all())->toBe(['requested', 'approved', 'in_transit', 'settled'])
        ->and($transfer->events->every(fn ($event) => $event->user_id === $user->id))->toBeTrue()
        ->and((int) LedgerEntry::where('source_type', $transfer->getMorphClass())->sum('amount_minor'))->toBe(0);
});

test('receiving less than was sent is reconciled as a fee', function () {
    $user = User::factory()->create();
    $source = treasuryAccount($user, 'Collections');
    $float = treasuryAccount($user, 'Float');
    fund($source, '1000');

    requestTransfer($user, $source, $float);
    $transfer = TreasuryTransfer::sole();
    $this->actingAs($user)->post(route('treasury.transfers.approve', $transfer));
    $this->actingAs($user)->post(route('treasury.transfers.send', $transfer));
    $this->actingAs($user)->post(route('treasury.transfers.settle', $transfer), ['received' => '297.50']);

    expect((string) LedgerBalance::of($float, 'ZMW'))->toBe('297.50')
        ->and($transfer->refresh()->received_minor)->toBe(29750)
        ->and((int) LedgerEntry::where('source_type', $transfer->getMorphClass())->sum('amount_minor'))->toBe(0)
        ->and($transfer->events->last()->note)->toBe('Received 297.50 of 300.00 ZMW');
});

test('a failed transfer reverses its effect on the ledger', function () {
    $user = User::factory()->create();
    $source = treasuryAccount($user, 'Collections');
    $float = treasuryAccount($user, 'Float');
    fund($source, '1000');

    requestTransfer($user, $source, $float);
    $transfer = TreasuryTransfer::sole();
    $this->actingAs($user)->post(route('treasury.transfers.approve', $transfer));
    $this->actingAs($user)->post(route('treasury.transfers.send', $transfer));

    expect((string) LedgerBalance::of($source, 'ZMW'))->toBe('700.00');

    $this->actingAs($user)->post(route('treasury.transfers.fail', $transfer), ['reason' => 'Bank returned the funds']);

    expect((string) LedgerBalance::of($source, 'ZMW'))->toBe('1000.00')
        ->and($transfer->refresh()->status)->toBe(TransferStatus::FAILED)
        ->and($transfer->failure_reason)->toBe('Bank returned the funds');
});

test('a transfer can\'t be approved or sent beyond the source balance', function () {
    $user = User::factory()->create();
    $source = treasuryAccount($user, 'Collections');
    $float = treasuryAccount($user, 'Float');
    fund($source, '100');

    requestTransfer($user, $source, $float, ['amount' => '100', 'fee' => '1']);
    $transfer = TreasuryTransfer::sole();

    $this->actingAs($user)
        ->post(route('treasury.transfers.approve', $transfer))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Insufficient balance: Collections holds 100.00 ZMW, and this transfer needs 101.00.']);

    expect($transfer->refresh()->status)->toBe(TransferStatus::REQUESTED);
});

test('steps must follow the lifecycle', function () {
    $user = User::factory()->create();
    $source = treasuryAccount($user, 'Collections');
    $float = treasuryAccount($user, 'Float');
    fund($source, '1000');

    requestTransfer($user, $source, $float);
    $transfer = TreasuryTransfer::sole();

    // Can't send or settle before approval.
    $this->actingAs($user)
        ->post(route('treasury.transfers.send', $transfer))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'A requested transfer can\'t become in transit.']);
    $this->actingAs($user)->post(route('treasury.transfers.settle', $transfer));

    // Cancelled is final.
    $this->actingAs($user)->post(route('treasury.transfers.cancel', $transfer));
    $this->actingAs($user)->post(route('treasury.transfers.approve', $transfer));

    expect($transfer->refresh()->status)->toBe(TransferStatus::CANCELLED)
        ->and(LedgerEntry::where('source_type', $transfer->getMorphClass())->count())->toBe(0);
});

test('a transfer needs two different own accounts and a valid amount', function () {
    $user = User::factory()->create();
    $mine = treasuryAccount($user, 'Mine');
    $theirs = treasuryAccount(User::factory()->create(), 'Theirs');

    requestTransfer($user, $mine, $mine)->assertSessionHasErrors('to_provider_id');
    requestTransfer($user, $mine, $theirs)->assertSessionHasErrors('to_provider_id');
    requestTransfer($user, $mine, treasuryAccount($user, 'Other'), ['amount' => '10.005'])->assertSessionHasErrors('amount');

    expect(TreasuryTransfer::count())->toBe(0);
});

test('another account\'s transfers and rules can\'t be touched', function () {
    $owner = User::factory()->create();
    $source = treasuryAccount($owner, 'Collections');
    fund($source, '1000');
    requestTransfer($owner, $source, treasuryAccount($owner, 'Float'));
    $transfer = TreasuryTransfer::sole();
    $rule = floatRule($source, 100);

    $intruder = User::factory()->create();

    $this->actingAs($intruder)->post(route('treasury.transfers.approve', $transfer))->assertForbidden();
    $this->actingAs($intruder)->delete(route('treasury.float-rules.destroy', $rule))->assertForbidden();
});

// The page

test('the treasury page shows balances, float status, alerts, transfers and entries', function () {
    $user = User::factory()->create();
    $source = treasuryAccount($user, 'Collections');
    $float = treasuryAccount($user, 'Payout float', SandboxPayoutController::class);
    fund($source, '1000');
    fund($float, '100');
    floatRule($float, 50000, 200000);
    floatRule($source, 10000);

    requestTransfer($user, $source, $float);
    $transfer = TreasuryTransfer::sole();
    $this->actingAs($user)->post(route('treasury.transfers.approve', $transfer));
    $this->actingAs($user)->post(route('treasury.transfers.send', $transfer));

    $this->actingAs($user)
        ->get(route('treasury.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('treasury/index')
            ->where('accounts.data', fn ($accounts) => collect($accounts)->firstWhere('provider', 'Collections') === [
                'providerId' => $source->id,
                'provider' => 'Collections',
                'logo' => '/lenco.png',
                'currency' => 'ZMW',
                'balance' => '700.00',
                'inTransitOut' => '300.00',
                'estimated' => false,
                'rule' => ['id' => FloatRule::where('payment_provider_id', $source->id)->value('id'), 'minimum' => '100.00', 'target' => null, 'active' => true],
                'status' => 'ok',
            ] && collect($accounts)->firstWhere('provider', 'Payout float')['status'] === 'low')
            ->has('alerts', 1)
            ->where('alerts.0.provider', 'Payout float')
            ->where('alerts.0.target', '2000.00')
            ->where('transfers.data.0.status', 'in_transit')
            ->where('transfers.data.0.amount', '300.00')
            ->has('transfers.data.0.events', 3)
            ->where('entries.data.0.type', 'transfer_out')
            ->where('entries.data.0.reference', $transfer->reference)
            ->where('providers', fn ($providers) => collect($providers)->firstWhere('name', 'Collections')['currencies'] === ['ZMW']));
});

test('guests can\'t open the treasury', function () {
    $this->get(route('treasury.index'))->assertRedirect(route('login'));
});

test('each treasury table pages on its own, with its default size', function () {
    $user = User::factory()->create();
    $accounts = collect(range(1, 7))->map(fn (int $i) => treasuryAccount($user, sprintf('Account %02d', $i)));
    $accounts->each(fn (PaymentProvider $account) => fund($account, '1000'));

    foreach (range(1, 6) as $i) {
        TreasuryTransfer::create([
            'reference' => (string) Str::uuid(), 'user_id' => $user->id, 'from_provider_id' => $accounts[0]->id,
            'to_provider_id' => $accounts[1]->id, 'currency' => 'ZMW', 'amount_minor' => $i * 100, 'status' => TransferStatus::REQUESTED,
        ]);
    }

    $this->actingAs($user)
        ->get(route('treasury.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.per_page', 5)->where('accounts.total', 7)->has('accounts.data', 5)
            ->where('transfers.per_page', 5)->where('transfers.total', 6)->has('transfers.data', 5)
            // Seven opening balances, so seven provider-account entries.
            ->where('entries.per_page', 10)->where('entries.total', 7)->has('entries.data', 7)
            ->where('perPageOptions.entries', [10, 25, 50]));

    // Paging one table leaves the others on their first page.
    $this->actingAs($user)
        ->get(route('treasury.index', ['accounts' => 2, 'transfersPer' => 10]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.current_page', 2)->has('accounts.data', 2)
            ->where('accounts.data.0.provider', 'Account 06')
            ->where('transfers.current_page', 1)->where('transfers.per_page', 10)->has('transfers.data', 6));
});

test('unsupported page sizes fall back to the default', function () {
    $user = User::factory()->create();
    treasuryAccount($user, 'Only');

    $this->actingAs($user)
        ->get(route('treasury.index', ['accountsPer' => 7, 'entriesPer' => 5]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.per_page', 5)
            ->where('entries.per_page', 10));
});

test('each treasury table has its own search', function () {
    $user = User::factory()->create();
    $collections = treasuryAccount($user, 'Collections');
    $float = treasuryAccount($user, 'Payout float', SandboxPayoutController::class);
    floatRule($float, 50000);
    fund($collections, '1000');
    fund($float, '100');

    requestTransfer($user, $collections, $float, ['note' => 'Weekly top up']);
    requestTransfer($user, $float, $collections, ['amount' => '10', 'note' => 'Return']);
    $topUp = TreasuryTransfer::where('note', 'Weekly top up')->sole();

    $this->actingAs($user)
        ->get(route('treasury.index', ['aq' => 'below minimum', 'tq' => 'weekly', 'lq' => 'payout float']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['accounts' => 'below minimum', 'transfers' => 'weekly', 'entries' => 'payout float'])
            ->has('accounts.data', 1)->where('accounts.data.0.provider', 'Payout float')
            ->has('transfers.data', 1)->where('transfers.data.0.reference', $topUp->reference)
            ->has('entries.data', 1)->where('entries.data.0.provider', 'Payout float'));

    // Transfers by status and reference; entries by type.
    $this->actingAs($user)
        ->get(route('treasury.index', ['tq' => substr($topUp->reference, 0, 8), 'lq' => 'opening balance']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('transfers.data', 1)
            ->has('entries.data', 2));

    $this->actingAs($user)
        ->get(route('treasury.index', ['tq' => 'requested']))
        ->assertInertia(fn (Assert $page) => $page->has('transfers.data', 2));
});

test('actions return to the same filtered view', function () {
    $user = User::factory()->create();
    $source = treasuryAccount($user, 'Collections');
    fund($source, '1000');
    requestTransfer($user, $source, treasuryAccount($user, 'Float'));
    $transfer = TreasuryTransfer::sole();

    $from = route('treasury.index', ['tq' => 'collections', 'transfers' => 1]);

    $this->actingAs($user)
        ->from($from)
        ->post(route('treasury.transfers.approve', $transfer))
        ->assertRedirect($from);
});

// Rebalancing suggestions (treasury agent)

/**
 * A low payout float and a well-funded collections account, with an AI key.
 *
 * @return array{0: User, 1: PaymentProvider, 2: PaymentProvider}
 */
function rebalanceSetup(): array
{
    config(['ai.default' => 'anthropic', 'ai.providers.anthropic.key' => 'test-key']);

    $user = User::factory()->create();
    $collections = treasuryAccount($user, 'Collections');
    $float = treasuryAccount($user, 'Payout float', SandboxPayoutController::class);
    floatRule($collections, 50000);
    floatRule($float, 100000, 300000);
    fund($collections, '5000');
    fund($float, '500');

    return [$user, $collections, $float];
}

function suggestion(PaymentProvider $from, PaymentProvider $to, string $amount, string $currency = 'ZMW'): array
{
    return ['from_provider_id' => $from->id, 'to_provider_id' => $to->id, 'currency' => $currency, 'amount' => $amount, 'reasoning' => "Top up {$to->name} from {$from->name}."];
}

test('the treasury agent proposes transfers that wait for approval and move no money', function () {
    [$user, $collections, $float] = rebalanceSetup();
    TreasuryAgent::fake([[
        'summary' => 'The payout float is below its minimum.',
        'suggestions' => [suggestion($collections, $float, '2500')],
        'notes' => [],
    ]]);

    $this->actingAs($user)->post(route('treasury.suggestions.store'))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('rebalance.summary', 'The payout float is below its minimum.')
        ->assertInertiaFlash('rebalance.rejected', []);

    $transfer = TreasuryTransfer::sole();
    expect($transfer->status)->toBe(TransferStatus::REQUESTED)
        ->and($transfer->origin)->toBe('agent')
        ->and($transfer->reasoning)->toBe('Top up Payout float from Collections.')
        ->and($transfer->amount_minor)->toBe(250000)
        ->and($transfer->fee_minor)->toBe(0)
        ->and((string) LedgerBalance::of($float, 'ZMW'))->toBe('500.00')
        ->and((string) LedgerBalance::of($collections, 'ZMW'))->toBe('5000.00');

    TreasuryAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '"max_can_receive": "2500.00"')
        && str_contains($prompt->prompt, '"max_can_send": "4500.00"'));

    // A person approves it through the normal gate.
    $this->actingAs($user)->post(route('treasury.transfers.approve', $transfer))->assertRedirect();
    expect($transfer->fresh()->status)->toBe(TransferStatus::APPROVED);

    $this->actingAs($user)->get(route('treasury.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('aiReady', true)
            ->where('transfers.data.0.origin', 'agent')
            ->where('transfers.data.0.reasoning', 'Top up Payout float from Collections.'));
});

test('guardrails refuse suggestions outside what the accounts can send and need', function () {
    [$user, $collections, $float] = rebalanceSetup();
    $stranger = treasuryAccount(User::factory()->create(), 'Not mine');
    $usd = treasuryAccount($user, 'PayPal');
    fund($usd, '900', 'USD');

    TreasuryAgent::fake([[
        'summary' => 'Several options.',
        'suggestions' => [
            suggestion($collections, $float, '3000'),        // more than the float needs (2500)
            suggestion($stranger, $float, '100'),            // not the user's account
            suggestion($usd, $float, '100', 'USD'),          // the float holds no USD
            suggestion($collections, $float, 'lots'),        // not a number
            suggestion($collections, $collections, '100'),   // same account
            suggestion($collections, $float, '1200'),        // allowed
            suggestion($collections, $float, '1400'),        // the float only needs 1300 more
        ],
        'notes' => ['USD in PayPal could help once FX is available.'],
    ]]);

    $response = $this->actingAs($user)->post(route('treasury.suggestions.store'))
        ->assertInertiaFlash('rebalance.notes', ['USD in PayPal could help once FX is available.']);

    expect(TreasuryTransfer::pluck('amount_minor')->all())->toBe([120000]);

    $rejected = collect(data_get($response->getSession()->get('inertia.flash_data'), 'rebalance.rejected'));
    expect($rejected)->toHaveCount(6)
        ->and($rejected->pluck('reason')->implode(' | '))
        ->toContain('more than Payout float needs')
        ->toContain('isn\'t yours')
        ->toContain('needs FX')
        ->toContain('isn\'t a number')
        ->toContain('same account');
});

test('a source can\'t be drawn below its own minimum across one run', function () {
    [$user, , $float] = rebalanceSetup();
    $second = treasuryAccount($user, 'Second float', SandboxPayoutController::class);
    floatRule($second, 100000);
    $small = treasuryAccount($user, 'Small source');
    floatRule($small, 50000);
    fund($small, '1500');

    TreasuryAgent::fake([[
        'summary' => 'Two floats are low.',
        'suggestions' => [suggestion($small, $float, '800'), suggestion($small, $second, '300')],
        'notes' => [],
    ]]);

    $this->actingAs($user)->post(route('treasury.suggestions.store'))
        ->assertInertiaFlash('rebalance.rejected.0.reason', 'It would take Small source below its own minimum float (it can spare 200.00 ZMW).');

    // Small source holds 1500 with a 500 minimum: 1000 to spare, 800 already used.
    expect(TreasuryTransfer::pluck('amount_minor')->all())->toBe([80000]);
});

test('transfers already on the way count towards a float', function () {
    [$user, $collections, $float] = rebalanceSetup();
    requestTransfer($user, $collections, $float, ['amount' => '2500']);

    TreasuryAgent::fake();

    $this->actingAs($user)->post(route('treasury.suggestions.store'))
        ->assertInertiaFlash('rebalance.askedAgent', false)
        ->assertInertiaFlash('toast.message', 'All floats are healthy. Nothing to suggest.');

    TreasuryAgent::assertNeverPrompted();
});

test('suggestions need an AI provider and report agent failures', function () {
    [$user] = rebalanceSetup();
    config(['ai.providers.anthropic.key' => null]);
    TreasuryAgent::fake();

    $this->actingAs($user)->post(route('treasury.suggestions.store'))
        ->assertInertiaFlash('toast.type', 'error');
    TreasuryAgent::assertNeverPrompted();

    config(['ai.providers.anthropic.key' => 'test-key']);
    TreasuryAgent::fake(fn () => throw new RuntimeException('Provider overloaded'));

    $this->actingAs($user)->post(route('treasury.suggestions.store'))
        ->assertInertiaFlash('toast.type', 'error')
        ->assertInertiaFlash('toast.message', 'The treasury agent could not respond: Provider overloaded');

    expect(TreasuryTransfer::count())->toBe(0);
});

test('the agent sees recent payout volume and one run records at most five transfers', function () {
    [$user, $collections, $float] = rebalanceSetup();
    settledPayout($float, 140);

    TreasuryAgent::fake([[
        'summary' => 'Small top-ups.',
        'suggestions' => array_fill(0, 7, suggestion($collections, $float, '100')),
        'notes' => [],
    ]]);

    $this->actingAs($user)->post(route('treasury.suggestions.store'))
        ->assertInertiaFlash('rebalance.rejected.1.reason', 'Only 5 transfers are suggested at a time.');

    expect(TreasuryTransfer::count())->toBe(5);

    TreasuryAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, '"payouts_last_7_days": "140.00"')
        && str_contains($prompt->prompt, '"days_of_payouts_covered": 17.9'));
});

test('a cross-currency transfer converts between currencies with fx rate and target amount', function () {
    $user = User::factory()->create();
    $usdAccount = treasuryAccount($user, 'PayPal USD');
    $zmwAccount = treasuryAccount($user, 'Payout float', SandboxPayoutController::class);
    fund($usdAccount, '1000', 'USD');
    fund($zmwAccount, '100', 'ZMW');

    $this->actingAs($user)->post(route('treasury.transfers.store'), [
        'from_provider_id' => $usdAccount->id,
        'to_provider_id' => $zmwAccount->id,
        'currency' => 'USD',
        'to_currency' => 'ZMW',
        'amount' => '100',
        'fee' => '2',
        'note' => 'Cross currency funding',
    ])->assertRedirect(route('treasury.index'));

    $transfer = TreasuryTransfer::sole();
    expect($transfer->isFx())->toBeTrue()
        ->and($transfer->currency)->toBe('USD')
        ->and($transfer->destinationCurrency())->toBe('ZMW')
        ->and($transfer->amount_minor)->toBe(10000)
        ->and($transfer->fee_minor)->toBe(200)
        ->and($transfer->target_amount_minor)->toBe(270875)
        ->and((float) $transfer->fx_rate)->toBe(27.0875);

    // Approve
    $this->actingAs($user)->post(route('treasury.transfers.approve', $transfer));
    expect($transfer->fresh()->status)->toBe(TransferStatus::APPROVED);

    // Send: debits USD account (100 + 2 fee)
    $this->actingAs($user)->post(route('treasury.transfers.send', $transfer), ['external_reference' => 'FX-WIRE-1']);
    expect((string) LedgerBalance::of($usdAccount, 'USD'))->toBe('898.00')
        ->and((string) LedgerBalance::of($zmwAccount, 'ZMW'))->toBe('100.00');

    // Settle: credits ZMW account with target amount (2,708.75 ZMW)
    $this->actingAs($user)->post(route('treasury.transfers.settle', $transfer));
    expect((string) LedgerBalance::of($zmwAccount, 'ZMW'))->toBe('2808.75')
        ->and($transfer->refresh()->status)->toBe(TransferStatus::SETTLED)
        ->and($transfer->received_minor)->toBe(270875);
});

test('the treasury agent proposes cross-currency rebalancing transfers with fx conversion', function () {
    [$user, $collections, $float] = rebalanceSetup();
    $paypal = treasuryAccount($user, 'PayPal USD');
    fund($paypal, '1000', 'USD');

    TreasuryAgent::fake([[
        'summary' => 'Cross-currency rebalancing suggested.',
        'suggestions' => [[
            'from_provider_id' => $paypal->id,
            'to_provider_id' => $float->id,
            'currency' => 'USD',
            'to_currency' => 'ZMW',
            'amount' => '50',
            'reasoning' => 'Fund ZMW float from PayPal USD via FX.',
        ]],
        'notes' => ['Leveraging PayPal USD balances.'],
    ]]);

    $this->actingAs($user)->post(route('treasury.suggestions.store'))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success')
        ->assertInertiaFlash('rebalance.summary', 'Cross-currency rebalancing suggested.');

    $transfer = TreasuryTransfer::sole();
    expect($transfer->status)->toBe(TransferStatus::REQUESTED)
        ->and($transfer->origin)->toBe('agent')
        ->and($transfer->isFx())->toBeTrue()
        ->and($transfer->currency)->toBe('USD')
        ->and($transfer->destinationCurrency())->toBe('ZMW')
        ->and($transfer->amount_minor)->toBe(5000)
        ->and($transfer->target_amount_minor)->toBe(135438)
        ->and($transfer->reasoning)->toBe('Fund ZMW float from PayPal USD via FX.');
});
