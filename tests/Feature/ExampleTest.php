<?php

use App\Support\Coverage;
use Inertia\Testing\AssertableInertia;

test('the landing page renders with the supported countries', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('welcome')
            ->has('countries')
            ->where('countries.0.code', fn ($code) => is_string($code) && strlen($code) === 2)
        );
});

test('the coverage helper returns supported countries with code and name', function () {
    $countries = Coverage::countries();

    expect($countries)->not->toBeEmpty();
    expect($countries[0])->toHaveKeys(['code', 'name']);
    // Zambia is covered by several drivers, so it must be present.
    expect(collect($countries)->pluck('code'))->toContain('ZM');
});

test('the coverage helper reports headline stats', function () {
    $stats = Coverage::stats();

    expect($stats)->toHaveKeys(['countries', 'providers', 'currencies']);
    expect($stats['countries'])->toBe(count(Coverage::countries()));
    expect($stats['providers'])->toBeGreaterThan(1);
    expect($stats['currencies'])->toBeGreaterThan(1);
});
