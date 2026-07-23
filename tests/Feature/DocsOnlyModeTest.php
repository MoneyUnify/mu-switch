<?php

use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

test('docs only mode keeps only the landing page and documentation routes available', function () {
    if (! config('app.docs_only')) {
        $this->markTestSkipped('Run with DOCS_ONLY=true to verify docs-only route registration.');
    }

    $routeNames = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route) => $route->getName())
        ->filter()
        ->values();

    expect($routeNames)
        ->toContain('home')
        ->toContain('prezet.index')
        ->not->toContain('login')
        ->not->toContain('dashboard')
        ->not->toContain('providers.index')
        ->not->toContain('api-token.show')
        ->not->toContain('sanctum.csrf-cookie')
        ->not->toContain('storage.local')
        ->not->toContain('boost.browser-logs');

    expect(Route::getRoutes())->toHaveCount(6);

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('welcome')
            ->where('githubUrl', 'https://github.com/moneyUnify/mu-switch')
            ->where('docsOnly', true)
        );

    $this->get('/docs')->assertOk();
    $this->get('/login')->assertNotFound();
    $this->get('/dashboard')->assertNotFound();
    $this->post('/api/v1/payment/request')->assertNotFound();
});
