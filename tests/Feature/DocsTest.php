<?php

use Illuminate\Support\Facades\Artisan;

// The /docs routes read from a file-based Prezet index (database/prezet.sqlite),
// which is built by `docs:index` rather than the test migrations. Build it once
// if it is missing (CI, a fresh clone) so the docs pages render instead of 500.
beforeEach(function () {
    if (! file_exists(base_path('database/prezet.sqlite'))) {
        Artisan::call('docs:index', ['--fresh' => true]);
    }
});

test('the documentation index is served under /docs', function () {
    $this->get('/docs')
        ->assertOk()
        ->assertSee('API Reference', false)
        ->assertSee('Self-Hosted Installation', false);
});

test('a documentation page renders its markdown content', function () {
    $this->get('/docs/api/request-payment')
        ->assertOk()
        ->assertSee('Request a Payment', false);
});

test('the installation guide is reachable', function () {
    $this->get('/docs/installation')
        ->assertOk()
        ->assertSee('Self-Hosted Installation', false);
});

test('unknown documentation slugs return 404', function () {
    $this->get('/docs/this-does-not-exist')->assertNotFound();
});

test('markdown tables render as html tables, not raw pipes', function () {
    $html = $this->get('/docs/index')->assertOk()->getContent();

    expect($html)->toContain('<table')
        ->and($html)->toContain('<thead')
        ->and($html)->not->toContain('| Concept |');
});

test('documentation pages are MoneyUnify-branded and free of vendor branding', function () {
    $pages = ['/docs', '/docs/index', '/docs/installation', '/docs/api/request-payment'];

    foreach ($pages as $page) {
        $html = $this->get($page)->assertOk()->getContent();

        expect($html)->toContain('MoneyUnify Switch');
        expect(strtolower($html))->not->toContain('laravel');
        expect(strtolower($html))->not->toContain('prezet');
    }
});

test('the sidebar watermark credits MoneyUnify and Blessed Jason Mwanza', function () {
    $this->get('/docs')
        ->assertOk()
        ->assertSee('Powered by MoneyUnify', false)
        ->assertSee('https://github.com/blessedjasonmwanza/MoneyUnify', false)
        ->assertSee('Blessed Jason Mwanza', false)
        ->assertSee('https://github.com/blessedjasonmwanza/', false);
});

test('the documentation header uses the MoneyUnify logo', function () {
    $this->get('/docs')
        ->assertOk()
        ->assertSee('/moneyunify-icon.png', false);
});

test('the documentation header home links route to the landing page', function () {
    $this->get('/docs')
        ->assertOk()
        ->assertSee('aria-label="Home"', false)
        ->assertSee('href="'.route('home').'"', false)
        ->assertDontSee('href="'.url('/dashboard').'"', false);
});

test('in-page links resolve to valid /docs urls, not /docs/content', function () {
    $response = $this->get('/docs/index')->assertOk();

    // The "Next steps" links must point at real slugs...
    $response->assertSee('href="/docs/installation"', false);
    $response->assertSee('href="/docs/providers"', false);

    // ...and must never contain the broken content/ prefix.
    expect($response->getContent())->not->toContain('/docs/content/');
});

test('the ai and mcp setup guide is reachable and unbranded', function () {
    $html = $this->get('/docs/ai-and-mcp')
        ->assertOk()
        ->assertSee('AI &amp; MCP Setup', false)
        ->assertSee('ANTHROPIC_API_KEY', false)
        ->assertSee('MCP_PAYPAL_TOKEN', false)
        ->assertSee('fail over', false)
        ->getContent();

    expect(strtolower($html))->not->toContain('laravel');
});

test('the providers guide documents the paypal driver and its webhook', function () {
    $this->get('/docs/providers')
        ->assertOk()
        ->assertSee('Built-in driver: PayPal', false)
        ->assertSee('/api/webhooks/paypal/{provider_id}', false);
});

test('the quote api is documented', function () {
    $this->get('/docs/api/quote-payment')
        ->assertOk()
        ->assertSee('Quote a Payment', false)
        ->assertSee('/api/v1/payment/quote', false)
        ->assertSee('payer_pays_fees', false);
});

test('the payout api is documented', function () {
    $this->get('/docs/api/payouts')
        ->assertOk()
        ->assertSee('Send a Payout', false)
        ->assertSee('Idempotency-Key', false)
        ->assertSee('/api/v1/payout/verify', false);
});

test('the treasury guide is documented', function () {
    $this->get('/docs/treasury')
        ->assertOk()
        ->assertSee('Float rules and alerts', false)
        ->assertSee('Confirm received', false)
        ->assertSee('Rebalancing suggestions (AI)', false);
});

test('the fx api is documented', function () {
    $this->get('/docs/api/fx')
        ->assertOk()
        ->assertSee('Exchange Rates &amp; FX Quotes', false)
        ->assertSee('/api/v1/fx/quote', false)
        ->assertSee('account that created it', false);
});

test('cross-border transfers are documented', function () {
    $this->get('/docs/api/cross-border-transfers')
        ->assertOk()
        ->assertSee('Cross-Border Transfers', false)
        ->assertSee('/api/v1/transfer/request', false)
        ->assertSee('refund_failed', false);
});

test('lenco payouts are documented', function () {
    $this->get('/docs/providers')
        ->assertOk()
        ->assertSee('Payouts through Lenco', false)
        ->assertSee('/transfers/mobile-money', false);
});

test('the send money page and assistant are documented', function () {
    $this->get('/docs/ai-and-mcp')
        ->assertOk()
        ->assertSee('The Send Money assistant', false)
        ->assertSee('StartTransfer', false);

    $this->get('/docs/api/cross-border-transfers')
        ->assertOk()
        ->assertSee('TRANSFER_MAX_DAILY_USD', false)
        ->assertSee('From the dashboard', false);
});

test('refunds and merchants are documented', function () {
    $this->get('/docs/api/refunds')->assertOk()->assertSee('/api/v1/payment/refund', false)->assertSee('Refunds made in PayPal', false);
    $this->get('/docs/merchants')->assertOk()->assertSee('pay link', false)->assertSee('IMPACT_BENCHMARK_COST_PERCENT', false);
    $this->get('/docs/api/merchants')->assertOk()->assertSee('/api/v1/merchants/{merchant}/charge', false);
});

test('pay links are documented', function () {
    $this->get('/docs/merchants')->assertOk()->assertSee('archived', false)->assertSee('/pay/l/', false);
    $this->get('/docs/api/merchants')->assertOk()->assertSee('/api/v1/merchants/{merchant}/links', false);
});

test('paypal-verified receipts are documented', function () {
    $this->get('/docs/providers')->assertOk()->assertSee('PayPal-verified receipts', false)->assertSee('/receipts/paypal/', false);
});

test('guest card checkout is documented', function () {
    $this->get('/docs/providers')->assertOk()->assertSee('Guest card checkout')->assertSee('GUEST_CHECKOUT');
    $this->get('/docs/api/merchants')->assertOk()->assertSee('pay_with', false);
    $this->get('/docs/api/cross-border-transfers')->assertOk()->assertSee('pay_with', false);
});

test('payer emails are documented', function () {
    $this->get('/docs/api/cross-border-transfers')->assertOk()->assertSee('Emails to the sender');
    $this->get('/docs/providers')->assertOk()->assertSee('Refund emails');
});

test('paypal fx is documented', function () {
    $this->get('/docs/api/fx')->assertOk()->assertSee('PayPal FX')->assertSee('FX_RATE_CHANGE_DUE_TO_MARKET_EVENT');
    $this->get('/docs/providers')->assertOk()->assertSee('PayPal FX (exchange rates)', false);
});

test('the mcp servers are documented', function () {
    $this->get('/docs/mcp')->assertOk()
        ->assertSee('/mcp/operator')
        ->assertSee('/mcp/merchant')
        ->assertSee('charge_merchant_customer')
        ->assertSee('One switch, three ways to run it');
});

test('paypal\'s js sdk button is documented', function () {
    $this->get('/docs/providers')->assertOk()->assertSee('JS SDK v6')->assertSee('/pay/receipt/{reference}/capture', false);
});

test('paypal\'s agent toolkit setup is documented', function () {
    $this->get('/docs/ai-and-mcp')->assertOk()->assertSee('Agent Toolkit')->assertSee('PAYPAL_MCP_TRANSPORT')->assertSee('@paypal/mcp');
});

test('the pawapay v2 driver is documented', function () {
    $this->get('/docs/providers')->assertOk()->assertSee('pawaPay API v2')->assertSee('/v2/payouts')->assertDontSee('predict-correspondent');
});

test('the coverage docs count paypal\'s markets', function () {
    $this->get('/docs/api/coverage')->assertOk()->assertSee('201 markets')->assertSee('204 countries reached');
});

test('the openapi spec is linked from the docs', function () {
    $this->get('/docs/api/overview')->assertOk()->assertSee('/openapi.yaml', false);
    $this->get('/docs/api/request-payment')->assertOk()->assertSee('pay_with')->assertDontSee('one of `ZM`, `MW`', false);
});
