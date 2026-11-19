<?php

use Inertia\Testing\AssertableInertia;

test('the privacy policy and terms are public pages', function (string $route, string $component) {
    $this->get(route($route))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component($component));
})->with([
    'privacy policy' => ['privacy', 'legal/privacy'],
    'terms and conditions' => ['terms', 'legal/terms'],
]);

test('the terms require every deployment to keep the licence and credit the author', function () {
    $terms = file_get_contents(resource_path('js/pages/legal/terms.tsx'));

    expect($terms)->toContain('LICENSE.txt')
        ->toContain('NOTICE')
        ->toContain('Blessed Jason Mwanza')
        ->and(base_path('LICENSE.txt'))->toBeFile()
        ->and(file_get_contents(base_path('NOTICE')))->toContain('Copyright 2026 Blessed Jason Mwanza');
});

test('the privacy policy credits the home page photographers', function () {
    $privacy = file_get_contents(resource_path('js/pages/legal/privacy.tsx'));

    expect($privacy)->toContain('Ali Mkumbwa')
        ->toContain('Francis Odeyemi')
        ->toContain('Unsplash License');

    foreach (['mobile-money', 'aggregators', 'operators', 'merchants'] as $photo) {
        expect(public_path("images/landing/{$photo}.jpg"))->toBeFile();
    }
});
