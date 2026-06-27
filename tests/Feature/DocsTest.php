<?php

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

test('in-page links resolve to valid /docs urls, not /docs/content', function () {
    $response = $this->get('/docs/index')->assertOk();

    // The "Next steps" links must point at real slugs...
    $response->assertSee('href="/docs/installation"', false);
    $response->assertSee('href="/docs/providers"', false);

    // ...and must never contain the broken content/ prefix.
    expect($response->getContent())->not->toContain('/docs/content/');
});
