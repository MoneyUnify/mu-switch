<?php

test('the landing page exposes SEO and social-share meta tags', function () {
    $response = $this->get(route('home'))->assertOk();

    // Primary SEO meta.
    $response->assertSee('name="description"', false);
    $response->assertSee('rel="canonical"', false);
    $response->assertSee('name="robots"', false);

    // Open Graph, used by Facebook, LinkedIn, WhatsApp, Slack, etc.
    $response->assertSee('property="og:title"', false);
    $response->assertSee('property="og:image"', false);
    $response->assertSee('/og-image.png', false);

    // Twitter / X large-image card.
    $response->assertSee('name="twitter:card"', false);
    $response->assertSee('summary_large_image', false);

    // Machine-readable overview for AI assistants.
    $response->assertSee('/llms.txt', false);

    // The page is branded, not the default Laravel title.
    $response->assertSee('MoneyUnify', false);
    $response->assertDontSee('<title>Laravel</title>', false);
});

test('the static SEO files are present and well-formed', function () {
    $publicPath = public_path();

    expect(file_get_contents("{$publicPath}/llms.txt"))
        ->toStartWith('# MoneyUnify Switch');

    expect(file_get_contents("{$publicPath}/llms-full.txt"))
        ->toContain('POST /api/v1/payment/request');

    expect(file_get_contents("{$publicPath}/sitemap.xml"))
        ->toContain('<urlset')
        ->toContain('https://moneyunify.one/');

    expect(file_get_contents("{$publicPath}/robots.txt"))
        ->toContain('Sitemap: https://moneyunify.one/sitemap.xml');

    expect(file_exists("{$publicPath}/og-image.png"))->toBeTrue();
});
