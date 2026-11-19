<?php

use App\Http\Controllers\Providers\PaypalController;
use App\Support\Currency;
use App\Support\Fees\FeeCalculator;
use Brick\Math\BigDecimal;

function paypalFees(string $currency = 'USD'): FeeCalculator
{
    return new FeeCalculator(PaypalController::FEE_SCHEDULE, $currency);
}

function lencoStyleFees(): FeeCalculator
{
    return new FeeCalculator([
        'collection' => ['percent' => 1.0, 'flat' => 0],
        'settlement' => ['tiers' => [['max' => 1000, 'fee' => 8.5]], 'default' => 12.0],
    ], 'ZMW');
}

test('paypal fees round half-up to the cent', function (string $amount, string $fee, string $net) {
    $breakdown = paypalFees()->forPayerAmount($amount)->toArray();

    expect($breakdown['fees']['collection'])->toBe($fee)
        ->and($breakdown['merchant_receives'])->toBe($net);
})->with([
    'one dollar' => ['1.00', '0.52', '0.48'],
    'hundred dollars' => ['100', '3.98', '96.02'],
    'half-cent rounds up' => ['50.00', '2.24', '47.76'],
]);

test('the fixed fee is taken in the charged currency', function () {
    expect(paypalFees('EUR')->forPayerAmount('100')->toArray()['fees']['collection'])->toBe('3.88')
        ->and(paypalFees('GBP')->forPayerAmount('100')->toArray()['fees']['collection'])->toBe('3.88')
        ->and(paypalFees('JPY')->forPayerAmount('10000')->toArray()['fees']['collection'])->toBe('398');
});

test('grossing up charges the payer exactly enough for the merchant to receive the amount', function () {
    $breakdown = paypalFees()->forMerchantAmount('100')->toArray();

    expect($breakdown)->toBe([
        'payer_pays' => '104.12',
        'fees' => ['collection' => '4.12', 'settlement' => '0.00', 'total' => '4.12'],
        'merchant_receives' => '100.00',
    ]);
});

test('grossing up never leaves the merchant short, and charges no more than needed', function (string $currency, string $target) {
    $calculator = paypalFees($currency);
    $quote = $calculator->forMerchantAmount($target);
    $oneUnitLess = $quote->payerPays->minus(BigDecimal::one()->withPointMovedLeft(Currency::decimals($currency)));

    expect($quote->merchantReceives()->isGreaterThanOrEqualTo($target))->toBeTrue()
        ->and($calculator->forPayerAmount($oneUnitLess)->merchantReceives()->isLessThan($target))->toBeTrue();
})->with([
    ['USD', '0.01'],
    ['USD', '19.99'],
    ['USD', '1234.56'],
    ['EUR', '87.30'],
    ['JPY', '5000'],
    ['HUF', '12345'],
]);

test('settlement fees follow their tier', function () {
    expect(lencoStyleFees()->forPayerAmount('750')->toArray())->toBe([
        'payer_pays' => '750.00',
        'fees' => ['collection' => '7.50', 'settlement' => '8.50', 'total' => '16.00'],
        'merchant_receives' => '734.00',
    ])->and(lencoStyleFees()->forPayerAmount('5000')->toArray()['fees']['settlement'])->toBe('12.00')
        ->and(lencoStyleFees()->forMerchantAmount('750')->toArray()['payer_pays'])->toBe('766.16');
});

test('minimum and maximum fees are applied before rounding', function () {
    $capped = new FeeCalculator(['collection' => ['percent' => 2.0, 'flat' => 0, 'min' => 1.0, 'max' => 25.0]], 'ZMW');

    expect($capped->forPayerAmount('10')->toArray()['fees']['collection'])->toBe('1.00')
        ->and($capped->forPayerAmount('500')->toArray()['fees']['collection'])->toBe('10.00')
        ->and($capped->forPayerAmount('5000')->toArray()['fees']['collection'])->toBe('25.00');
});

test('zero-decimal currencies are quoted in whole units', function () {
    $xof = new FeeCalculator(['collection' => ['percent' => 2.5]], 'XOF');

    expect($xof->forPayerAmount('1001')->toArray())->toBe([
        'payer_pays' => '1001',
        'fees' => ['collection' => '25', 'settlement' => '0', 'total' => '25'],
        'merchant_receives' => '976',
    ]);
});

test('fees that would swallow any charge are rejected', function () {
    (new FeeCalculator(['collection' => ['percent' => 100]], 'USD'))->forMerchantAmount('10');
})->throws(InvalidArgumentException::class);

test('currency precision follows iso 4217', function () {
    expect(Currency::decimals('USD'))->toBe(2)
        ->and(Currency::decimals('zmw'))->toBe(2)
        ->and(Currency::decimals('JPY'))->toBe(0)
        ->and(Currency::decimals('XOF'))->toBe(0)
        ->and(Currency::decimals('KWD'))->toBe(3);
});
