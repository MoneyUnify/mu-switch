<?php

use App\Support\Coverage;
use App\Support\Paypal\PaypalMarkets;
use Inertia\Testing\AssertableInertia;

test('the landing page renders with the supported countries', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('welcome')
            ->has('countries')
            ->where('githubUrl', 'https://github.com/moneyUnify/mu-switch')
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

test('the landing page shows every country reached: paypal markets and mobile money', function () {
    $this->get(route('home'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('countries', count(Coverage::reach()))
            ->where('stats.reach', count(Coverage::reach()))
            ->where('stats.paypal_countries', count(PaypalMarkets::isoCodes()))
            ->where('stats.countries', count(Coverage::countries())));
});

test('reach joins paypal\'s markets with the mobile-money countries, marking each', function () {
    $reach = collect(Coverage::reach())->keyBy('code');

    expect(PaypalMarkets::CODES)->toHaveCount(201)
        ->and($reach)->toHaveCount(count(array_unique([...PaypalMarkets::isoCodes(), ...array_column(Coverage::countries(), 'code')])))
        // Both: PayPal pays in, mobile money pays out.
        ->and($reach['ZM'])->toMatchArray(['name' => 'Zambia', 'paypal' => true, 'mobile_money' => true])
        // PayPal only.
        ->and($reach['US'])->toMatchArray(['name' => 'United States', 'paypal' => true, 'mobile_money' => false])
        // PayPal lists China as C2; it's shown as China.
        ->and($reach['CN'])->toMatchArray(['name' => 'China', 'paypal' => true])
        ->and($reach)->not->toHaveKey('C2')
        // Every country has a real name, not just its code.
        ->and($reach->filter(fn (array $country): bool => $country['name'] === $country['code']))->toBeEmpty();
});
