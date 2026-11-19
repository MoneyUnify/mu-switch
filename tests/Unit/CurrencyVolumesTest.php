<?php

use App\Support\CurrencyVolumes;

function volume(string $currency, float $amount = 0, int $count = 0): array
{
    return ['currency' => $currency, 'amount' => $amount, 'count' => $count];
}

test('currencies with volume come first, then recent, supported and default ones, six in all', function () {
    $volumes = CurrencyVolumes::padded(
        [volume('ZMW', 2500, 3), volume('KES', 900, 1)],
        ['ZMW', 'MWK'],          // recently used
        ['GHS', 'mwk'],          // supported by providers
    );

    expect(array_column($volumes, 'currency'))->toBe(['ZMW', 'KES', 'MWK', 'GHS', 'USD', 'UGX'])
        ->and($volumes[2])->toBe(volume('MWK'));
});

test('usd is always shown', function () {
    $volumes = CurrencyVolumes::padded([], ['ZMW', 'KES', 'GHS', 'UGX', 'MWK', 'TZS', 'NGN']);

    expect($volumes)->toHaveCount(6)
        ->and(array_column($volumes, 'currency'))->toBe(['ZMW', 'KES', 'GHS', 'UGX', 'MWK', 'USD']);
});

test('every currency with volume is kept, even past six', function () {
    $busy = array_map(fn (string $code) => volume($code, 10, 1), ['ZMW', 'KES', 'GHS', 'UGX', 'MWK', 'TZS', 'NGN']);

    $volumes = CurrencyVolumes::padded($busy);

    expect(array_column($volumes, 'currency'))->toBe(['ZMW', 'KES', 'GHS', 'UGX', 'MWK', 'TZS', 'NGN', 'USD']);
});

test('a new account sees the defaults, with usd first', function () {
    expect(array_column(CurrencyVolumes::padded([]), 'currency'))->toBe(CurrencyVolumes::DEFAULTS);
});
