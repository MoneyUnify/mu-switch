<?php

use App\Enums\CrossBorderStatus;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Mcp\MerchantAccess;
use App\Mcp\Servers\MerchantServer;
use App\Mcp\Servers\OperatorServer;
use App\Mcp\Tools\Merchant as MerchantTools;
use App\Mcp\Tools\Operator;
use App\Models\CrossBorderTransfer;
use App\Models\Merchant;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\CrossBorder\CrossBorderTransferService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();

    $this->operator = User::factory()->create(['api_token' => 'operator-token']);
    paypalProvider($this->operator);
    PaymentProvider::create(['user_id' => $this->operator->id, 'name' => 'Simulated Payouts', 'class' => SandboxPayoutController::class, 'config' => ['supported_countries' => ['ZM']], 'is_active' => true]);
    $this->merchant = Merchant::factory()->for($this->operator)->create(['name' => 'Kitwe Crafts', 'wallet_number' => '260977123456', 'commission_percent' => 2]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
        // The first order is ORDER-1; each later one gets its own id, as in PayPal.
        '*/v2/checkout/orders' => function () {
            static $orders = 0;
            $orders++;

            return Http::response(paypalOrder($orders === 1 ? 'ORDER-1' : "ORDER-{$orders}"), 201);
        },
    ]);
});

/**
 * A JSON-RPC call to an MCP server over HTTP.
 *
 * @param  array<string, mixed>  $params
 */
function mcpCall(string $path, ?string $token, string $method, array $params = [])
{
    return test()->withHeaders(array_filter([
        'Authorization' => $token ? "Bearer {$token}" : null,
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2025-06-18',
    ]))->postJson($path, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
}

// Access

test('the operator server needs the account\'s api token', function () {
    mcpCall('/mcp/operator', null, 'tools/list')->assertUnauthorized();
    mcpCall('/mcp/operator', 'wrong', 'tools/list')->assertUnauthorized();

    $names = collect(mcpCall('/mcp/operator', 'operator-token', 'tools/list')->assertOk()->json('result.tools'))->pluck('name');

    expect($names)->toContain('quote_transfer', 'start_transfer', 'request_payment', 'refund_payment', 'request_payout', 'onboard_merchant', 'charge_merchant_customer', 'get_exchange_rates', 'treasury_balances');
});

test('the merchant server needs the merchant\'s own key, which can be revoked', function () {
    mcpCall('/mcp/merchant', 'operator-token', 'tools/list')->assertUnauthorized();

    $key = $this->merchant->issueMcpKey();
    expect($key)->toStartWith('mu_mk_')
        ->and($this->merchant->refresh()->mcp_key_hash)->toBe(hash('sha256', $key))
        ->and($this->merchant->toArray())->not->toHaveKey('mcp_key_hash');

    $names = collect(mcpCall('/mcp/merchant', $key, 'tools/list')->assertOk()->json('result.tools'))->pluck('name');
    expect($names)->toContain('merchant_profile', 'create_payment', 'list_payments', 'create_pay_link')
        ->and($names)->not->toContain('request_payout', 'refund_payment', 'onboard_merchant');

    $this->merchant->revokeMcpKey();
    mcpCall('/mcp/merchant', $key, 'tools/list')->assertUnauthorized();
});

test('a tool call over http runs as the operator and is logged', function () {
    mcpCall('/mcp/operator', 'operator-token', 'tools/call', ['name' => 'quote_transfer', 'arguments' => ['amount' => 2500, 'country' => 'ZM']])
        ->assertOk()
        ->assertJsonPath('result.structuredContent.data.sender_pays.amount', '92.30')
        ->assertJsonPath('result.structuredContent.data.recipient_gets.amount', '2500.00');

    $this->assertDatabaseHas('api_logs', ['user_id' => $this->operator->id, 'route' => 'mcp.operator']);
});

// Operator tools behave like the REST API

test('an operator quotes and starts a transfer, and the sender gets a paypal checkout', function () {
    OperatorServer::actingAs($this->operator)->tool(Operator\QuoteTransfer::class, ['amount' => 2500, 'country' => 'ZM'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('data.sender_pays.amount', '92.30')->where('data.fx.rate_source', 'moneyunify')->etc());

    OperatorServer::actingAs($this->operator)->tool(Operator\StartTransfer::class, ['amount' => 2500, 'country' => 'ZM', 'account_number' => '0977123456', 'recipient_name' => 'Mum', 'idempotency_key' => 'mcp-1'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 'awaiting_payment')->where('data.checkout_url', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1')->etc());

    // The same idempotency key replays the transfer instead of starting another.
    OperatorServer::actingAs($this->operator)->tool(Operator\StartTransfer::class, ['amount' => 2500, 'country' => 'ZM', 'account_number' => '0977123456', 'idempotency_key' => 'mcp-1'])->assertOk();

    $transfer = CrossBorderTransfer::sole();
    expect($transfer->recipient_name)->toBe('Mum');

    OperatorServer::actingAs($this->operator)->tool(Operator\CheckTransfer::class, ['reference' => $transfer->reference])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('status', 'paying_out')->etc());

    OperatorServer::actingAs($this->operator)->tool(Operator\ListTransfers::class, ['status' => 'paying_out'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('count', 1)->where('transfers.0.reference', $transfer->reference)->etc());
});

test('tool input is validated like the api', function () {
    OperatorServer::actingAs($this->operator)->tool(Operator\StartTransfer::class, ['amount' => 2500, 'country' => 'XX', 'account_number' => '0977123456'])
        ->assertHasErrors(['Invalid input']);

    expect(CrossBorderTransfer::count())->toBe(0);
});

test('an operator collects a direct payment and runs a gateway for its merchants', function () {
    OperatorServer::actingAs($this->operator)->tool(Operator\RequestPayment::class, ['amount' => 25, 'account_number' => 'payer@example.com', 'country' => 'ZM', 'currency' => 'USD', 'pay_with' => 'card'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('data.checkout_url', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1')->etc());

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v2/checkout/orders')
        && $request['payment_source']['paypal']['experience_context']['landing_page'] === 'GUEST_CHECKOUT');

    OperatorServer::actingAs($this->operator)->tool(Operator\OnboardMerchant::class, ['name' => 'Lusaka Bakes', 'country' => 'ZM', 'wallet_number' => '0966112233', 'commission_percent' => 1.5])
        ->assertOk();
    $bakery = Merchant::where('name', 'Lusaka Bakes')->sole();

    OperatorServer::actingAs($this->operator)->tool(Operator\CreatePayLink::class, ['merchant' => $bakery->slug, 'label' => 'Birthday cake', 'amount' => 300])->assertOk();
    OperatorServer::actingAs($this->operator)->tool(Operator\ChargeMerchantCustomer::class, ['merchant' => (string) $bakery->id, 'amount' => 300])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('data.checkout_url')->etc());

    expect($bakery->paymentLinks()->count())->toBe(1)
        ->and($bakery->settlements()->count())->toBe(1);
});

test('an operator cannot reach another operator\'s merchants', function () {
    $other = User::factory()->create();

    OperatorServer::actingAs($other)->tool(Operator\MerchantSettlements::class, ['merchant' => $this->merchant->slug])
        ->assertHasErrors(['Merchant not found']);
});

test('fx and treasury are readable', function () {
    OperatorServer::actingAs($this->operator)->tool(Operator\GetExchangeRates::class, ['base' => 'USD'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('data.rates.ZMW.rate_source', 'moneyunify')->etc());

    OperatorServer::actingAs($this->operator)->tool(Operator\TreasuryBalances::class)->assertOk();
});

// Merchant tools stay inside the merchant

test('a merchant charges a customer and follows the payment through to settlement', function () {
    app()->instance(MerchantAccess::class, new MerchantAccess($this->merchant));

    MerchantServer::tool(MerchantTools\MerchantProfile::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('data.name', 'Kitwe Crafts')->etc());

    MerchantServer::tool(MerchantTools\QuotePayment::class, ['amount' => 2500])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('data.customer_pays.amount', '92.30')->etc());

    MerchantServer::tool(MerchantTools\CreatePayment::class, ['amount' => 2500, 'payer_email' => 'buyer@example.com', 'reference' => 'INV-7'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('data.checkout_url', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER-1')->has('data.receipt_url')->etc());

    $payment = $this->merchant->settlements()->sole();

    MerchantServer::tool(MerchantTools\CheckPayment::class, ['reference' => $payment->reference])->assertOk();
    MerchantServer::tool(MerchantTools\CheckPayment::class, ['reference' => $payment->reference])->assertOk();
    expect($payment->refresh()->status)->toBe(CrossBorderStatus::DELIVERED);

    MerchantServer::tool(MerchantTools\ListPayments::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('data.total', 1)->etc());
});

test('a merchant manages its own pay links', function () {
    app()->instance(MerchantAccess::class, new MerchantAccess($this->merchant));

    MerchantServer::tool(MerchantTools\CreatePayLink::class, ['label' => 'Basket', 'amount' => 450])->assertOk();
    $link = $this->merchant->paymentLinks()->sole();

    MerchantServer::tool(MerchantTools\SetPayLinkActive::class, ['link' => $link->code, 'is_active' => false])->assertOk();
    expect($link->refresh()->is_active)->toBeFalse();

    MerchantServer::tool(MerchantTools\DeletePayLink::class, ['link' => $link->code])->assertOk();
    expect($this->merchant->paymentLinks()->count())->toBe(0);
});

test('a merchant never sees another merchant\'s payments or links', function () {
    $neighbour = Merchant::factory()->for($this->operator)->create(['wallet_number' => '260977000222']);
    [$theirs] = app(CrossBorderTransferService::class)->collectForMerchant($neighbour, ['amount' => '100']);
    $theirLink = $neighbour->paymentLinks()->create(['code' => 'THEIRS1', 'label' => 'Theirs', 'settle_currency' => 'ZMW', 'is_active' => true]);

    app()->instance(MerchantAccess::class, new MerchantAccess($this->merchant));

    MerchantServer::tool(MerchantTools\CheckPayment::class, ['reference' => $theirs->reference])->assertHasErrors(['Payment not found']);
    MerchantServer::tool(MerchantTools\SetPayLinkActive::class, ['link' => $theirLink->code, 'is_active' => false])->assertHasErrors();
    MerchantServer::tool(MerchantTools\ListPayments::class)
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('data.total', 0)->etc());

    expect($theirLink->refresh()->is_active)->toBeTrue();
});

// Dashboard

test('an operator issues, replaces and revokes a merchant\'s key, and only the hint is ever shown again', function () {
    $this->actingAs($this->operator);

    $first = $this->postJson(route('merchants.mcp-key.store', $this->merchant->id))
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.endpoint', route('mcp.merchant'))
        ->json('data.key');

    $this->get(route('merchants.index'))->assertInertia(fn ($page) => $page
        ->where('mcpEndpoint', route('mcp.merchant'))
        ->where('merchants.data.0.mcp.enabled', true)
        ->where('merchants.data.0.mcp.hint', substr($first, 0, 10).'…'.substr($first, -4))
        ->missing('merchants.data.0.mcp.key'));

    $second = $this->postJson(route('merchants.mcp-key.store', $this->merchant->id))->json('data.key');
    expect($second)->not->toBe($first);
    mcpCall('/mcp/merchant', $first, 'tools/list')->assertUnauthorized();
    mcpCall('/mcp/merchant', $second, 'tools/list')->assertOk();

    $this->deleteJson(route('merchants.mcp-key.destroy', $this->merchant->id))->assertOk()->assertJsonPath('data.enabled', false);
    mcpCall('/mcp/merchant', $second, 'tools/list')->assertUnauthorized();
});

test('only the merchant\'s operator can issue its key', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('merchants.mcp-key.store', $this->merchant->id))
        ->assertNotFound();

    expect($this->merchant->refresh()->mcp_key_hash)->toBeNull();
});

test('the ai page shows how to connect to the moneyunify mcp servers', function () {
    $this->actingAs($this->operator)->get(route('ai.index'))
        ->assertInertia(fn ($page) => $page
            ->where('moneyunifyMcp.operator', route('mcp.operator'))
            ->where('moneyunifyMcp.merchant', route('mcp.merchant')));
});

test('each mcp client has its own rate limit, so one busy merchant never locks out the operator or other merchants', function () {
    $busy = $this->merchant->issueMcpKey();
    $quiet = Merchant::factory()->for($this->operator)->create(['wallet_number' => '260977000333'])->issueMcpKey();

    foreach (range(1, 120) as $call) {
        mcpCall('/mcp/merchant', $busy, 'tools/list')->assertOk();
    }

    mcpCall('/mcp/merchant', $busy, 'tools/list')->assertTooManyRequests();
    mcpCall('/mcp/merchant', $quiet, 'tools/list')->assertOk();
    mcpCall('/mcp/operator', 'operator-token', 'tools/list')->assertOk();
});
