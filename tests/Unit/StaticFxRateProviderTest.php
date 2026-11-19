<?php

use App\Support\Fx\Providers\StaticFxRateProvider;
use Brick\Math\RoundingMode;

test('it resolves direct rates from the base currency', function () {
    $provider = new StaticFxRateProvider([
        'base_currency' => 'USD',
        'rates' => [
            'USD' => 1.0,
            'ZMW' => 27.50,
            'KES' => 130.00,
        ],
    ]);

    expect($provider->getRate('USD', 'ZMW')->isEqualTo('27.50'))->toBeTrue();
    expect($provider->getRate('USD', 'KES')->isEqualTo('130.00'))->toBeTrue();
});

test('it resolves inverse rates back to the base currency', function () {
    $provider = new StaticFxRateProvider([
        'base_currency' => 'USD',
        'rates' => [
            'USD' => 1.0,
            'ZMW' => 27.50,
        ],
    ]);

    $rate = $provider->getRate('ZMW', 'USD');
    expect($rate)->not->toBeNull();
    // 1 / 27.50 = 0.0363636364
    expect($rate->toScale(4, RoundingMode::HalfUp)->toFloat())->toEqual(0.0364);
});

test('it calculates cross-currency rates through base currency', function () {
    $provider = new StaticFxRateProvider([
        'base_currency' => 'USD',
        'rates' => [
            'USD' => 1.0,
            'EUR' => 0.92,
            'ZMW' => 27.50,
        ],
    ]);

    // EUR -> ZMW = 27.50 / 0.92 = 29.8913043478
    $rate = $provider->getRate('EUR', 'ZMW');
    expect($rate)->not->toBeNull();
    expect($rate->toScale(2, RoundingMode::HalfUp)->toFloat())->toEqual(29.89);
});

test('it returns 1 for identical currencies', function () {
    $provider = new StaticFxRateProvider([
        'base_currency' => 'USD',
        'rates' => ['USD' => 1.0, 'ZMW' => 27.50],
    ]);

    expect($provider->getRate('USD', 'USD')->isEqualTo(1))->toBeTrue();
    expect($provider->getRate('ZMW', 'ZMW')->isEqualTo(1))->toBeTrue();
});

test('it supports custom pair overrides and their inverse', function () {
    $provider = new StaticFxRateProvider([
        'base_currency' => 'USD',
        'rates' => ['USD' => 1.0],
        'custom_pairs' => [
            'USD_ZMW' => 28.00,
        ],
    ]);

    expect($provider->getRate('USD', 'ZMW')->isEqualTo('28.00'))->toBeTrue();
    // Inverse: 1 / 28 = 0.0357142857
    expect($provider->getRate('ZMW', 'USD')->toScale(4, RoundingMode::HalfUp)->toFloat())->toEqual(0.0357);
});

test('it returns null for unsupported currencies', function () {
    $provider = new StaticFxRateProvider([
        'base_currency' => 'USD',
        'rates' => ['USD' => 1.0, 'ZMW' => 27.50],
    ]);

    expect($provider->getRate('USD', 'XYZ'))->toBeNull();
    expect($provider->getRate('ABC', 'USD'))->toBeNull();
    expect($provider->supports('USD', 'XYZ'))->toBeFalse();
    expect($provider->supports('USD', 'ZMW'))->toBeTrue();
});

test('it lists all rates against a base currency', function () {
    $provider = new StaticFxRateProvider([
        'base_currency' => 'USD',
        'rates' => [
            'USD' => 1.0,
            'EUR' => 0.92,
            'ZMW' => 27.50,
        ],
    ]);

    $all = $provider->allRates('USD');
    expect($all)->toHaveKey('EUR', 0.92);
    expect($all)->toHaveKey('ZMW', 27.50);
});
