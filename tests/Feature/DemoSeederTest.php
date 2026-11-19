<?php

use App\Console\SetupScripts;
use App\Enums\CrossBorderStatus;
use App\Enums\LedgerEntryType;
use App\Http\Controllers\Providers\PaypalController;
use App\Models\CrossBorderTransfer;
use App\Models\LedgerEntry;
use App\Models\PaymentProvider;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

test('demo data is a week of real activity through the app\'s own flows', function () {
    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::where('email', DemoSeeder::EMAIL)->sole();
    $transfers = CrossBorderTransfer::where('user_id', $user->id)->get();

    expect($transfers)->toHaveCount(7)
        ->and($transfers->where('status', CrossBorderStatus::DELIVERED))->toHaveCount(5)
        ->and($transfers->where('status', CrossBorderStatus::REFUNDED))->toHaveCount(2)
        ->and($transfers->whereNotNull('merchant_id'))->toHaveCount(2)
        ->and($transfers->whereNotNull('payment_link_id'))->toHaveCount(1)
        ->and($transfers->where('origin', 'agent')->sole()->conversation_id)->not->toBeNull()
        ->and($transfers->min('created_at')->lt(now()->subDays(6)))->toBeTrue();

    // Each delivered transfer was captured on PayPal and paid out; refunds went back through PayPal.
    $delivered = $transfers->firstWhere('status', CrossBorderStatus::DELIVERED);
    expect(data_get($delivered->fundingTransaction->provider_response, 'order.purchase_units.0.payments.captures.0.status'))->toBe('COMPLETED')
        ->and($delivered->payoutTransaction->status->value)->toBe('success')
        ->and($transfers->firstWhere('status', CrossBorderStatus::REFUNDED)->refundTransaction->status->value)->toBe('success');

    expect(LedgerEntry::where('type', LedgerEntryType::OPENING_BALANCE->value)->exists())->toBeTrue()
        ->and(LedgerEntry::where('type', LedgerEntryType::PAYOUT->value)->count())->toBeGreaterThan(0)
        ->and(DB::table('agent_conversation_messages')->count())->toBe(4)
        ->and($user->merchants()->sole()->paymentLinks()->count())->toBe(1);

    // The demo PayPal provider keeps the history but can never call PayPal.
    $paypal = PaymentProvider::where('user_id', $user->id)->where('class', PaypalController::class)->sole();
    expect($paypal->is_active)->toBeFalse()
        ->and($paypal->config)->not->toHaveKey('client_id');
});

test('the demo account can sign in and sees its activity', function () {
    $this->artisan('demo:seed')->assertSuccessful();

    $this->post(route('login.store'), ['email' => DemoSeeder::EMAIL, 'password' => DemoSeeder::PASSWORD])->assertRedirect();
    $this->assertAuthenticated();

    $this->get(route('send.index'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('transfers.total', 7)->where('conversations.total', 1));
});

test('seeding twice adds nothing', function () {
    $this->artisan('demo:seed')->assertSuccessful();
    $this->artisan('demo:seed')->assertSuccessful();

    expect(User::where('email', DemoSeeder::EMAIL)->count())->toBe(1)
        ->and(CrossBorderTransfer::count())->toBe(7);
});

test('a paypal sandbox app in the environment is added to the demo account', function () {
    config(['services.paypal.sandbox_client_id' => 'sandbox-id', 'services.paypal.sandbox_client_secret' => 'sandbox-secret']);

    $this->artisan('demo:seed')->assertSuccessful();

    $sandbox = PaymentProvider::where('name', 'PayPal sandbox')->sole();
    expect($sandbox->is_active)->toBeTrue()
        ->and($sandbox->config['client_id'])->toBe('sandbox-id')
        ->and($sandbox->config['environment'])->toBe('sandbox');
});

test('demo data is never added in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('demo:seed')->assertFailed();

    expect(User::where('email', DemoSeeder::EMAIL)->exists())->toBeFalse();
});

test('composer setup only seeds demo data when asked', function () {
    expect(SetupScripts::wantsDemoData(['composer', 'setup']))->toBeFalse()
        ->and(SetupScripts::wantsDemoData(['composer', 'setup', '--demo-data']))->toBeTrue()
        ->and(SetupScripts::wantsDemoData(['--demo-data']))->toBeTrue();
});

test('demo receipts show paypal\'s record without ever calling paypal', function () {
    $this->artisan('demo:seed')->assertSuccessful();
    Http::fake();
    Http::preventStrayRequests();

    $payment = CrossBorderTransfer::where('status', CrossBorderStatus::DELIVERED)->whereNull('merchant_id')->first()->fundingTransaction;
    $capture = data_get($payment->provider_response, 'order.purchase_units.0.payments.captures.0.id');

    $this->get(URL::signedRoute('paypal.receipt', ['transaction' => $payment->transaction_id]))
        ->assertOk()
        ->assertSee($capture)
        ->assertSee('Verified with PayPal');

    Http::assertNothingSent();
});

test('a pawapay sandbox token makes the demo pay out through pawapay\'s real sandbox', function () {
    config(['services.pawapay.sandbox_token' => 'pawapay-sandbox-token']);

    $this->artisan('demo:seed')->assertSuccessful();

    $user = User::where('email', DemoSeeder::EMAIL)->sole();
    $pawapay = PaymentProvider::where('user_id', $user->id)->where('name', 'pawaPay sandbox')->sole();

    expect($pawapay->is_active)->toBeTrue()
        ->and($pawapay->config['environment'])->toBe('sandbox')
        ->and(PaymentProvider::where('user_id', $user->id)->where('name', 'Simulated Payouts')->value('is_active'))->toBeFalse()
        ->and(CrossBorderTransfer::where('user_id', $user->id)->count())->toBe(7); // the history is unchanged
});
