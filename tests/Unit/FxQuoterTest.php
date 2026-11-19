<?php

use App\Support\Fx\Exceptions\FxQuoteNotFoundException;
use App\Support\Fx\Exceptions\UnsupportedCurrencyPairException;
use App\Support\Fx\FxQuoter;
use App\Support\Fx\Providers\StaticFxRateProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->provider = new StaticFxRateProvider([
        'base_currency' => 'USD',
        'rates' => [
            'USD' => 1.0,
            'EUR' => 0.92,
            'ZMW' => 27.50,
            'UGX' => 3700.0,
            'KES' => 130.0,
        ],
    ]);
    $this->quoter = new FxQuoter($this->provider);
});

test('it quotes by source amount applying platform margin', function () {
    // 100 USD -> ZMW at 27.50 with 1.5% margin
    // Effective rate: 27.50 * (1 - 0.015) = 27.0875
    // Target amount: 100 * 27.0875 = 2708.75 ZMW
    // Margin amount: 100 * 0.015 = 1.50 USD
    $quote = $this->quoter->quote('USD', 'ZMW', '100.00', marginPercent: 1.5);

    expect((string) $quote->sourceAmount)->toBe('100.00');
    expect((string) $quote->targetAmount)->toBe('2708.75');
    expect((string) $quote->baseRate)->toBe('27.50000000');
    expect((string) $quote->effectiveRate)->toBe('27.08750000');
    expect((string) $quote->marginPercent)->toBe('1.5');
    expect((string) $quote->marginAmount)->toBe('1.50');
    expect($quote->isFx())->toBeTrue();
    expect($quote->isExpired())->toBeFalse();
    expect($quote->expiresInSeconds())->toBeGreaterThan(0);
});

test('it handles zero-decimal payout currencies like UGX properly', function () {
    // 50 USD -> UGX (3700 per USD with 1.5% margin)
    // Effective rate = 3700 * 0.985 = 3644.5
    // Target amount = 50 * 3644.5 = 182225 (UGX has 0 decimals)
    $quote = $this->quoter->quote('USD', 'UGX', '50.00', marginPercent: 1.5);

    expect((string) $quote->targetAmount)->toBe('182225');
    expect($quote->targetCurrency)->toBe('UGX');
});

test('it quotes by target amount guaranteeing coverage for recipient', function () {
    // Recipient needs 2,708.75 ZMW
    // Effective rate = 27.0875
    // Required source: 2708.75 / 27.0875 = 100.00 USD
    $quote = $this->quoter->quoteTarget('USD', 'ZMW', '2708.75', marginPercent: 1.5);

    expect((string) $quote->sourceAmount)->toBe('100.00');
    expect((string) $quote->targetAmount)->toBe('2708.75');
});

test('same currency quote has rate 1 and zero margin', function () {
    $quote = $this->quoter->quote('USD', 'USD', '250.00');

    expect((string) $quote->sourceAmount)->toBe('250.00');
    expect((string) $quote->targetAmount)->toBe('250.00');
    expect((string) $quote->effectiveRate)->toBe('1');
    expect((string) $quote->marginAmount)->toBe('0');
    expect($quote->isFx())->toBeFalse();
});

test('it stores and retrieves quotes from cache', function () {
    $quote = $this->quoter->quote('USD', 'ZMW', '100.00', ownerId: 7);

    $retrieved = $this->quoter->findQuote($quote->id, 7);
    expect($retrieved)->not->toBeNull();
    expect($retrieved->id)->toBe($quote->id);
    expect((string) $retrieved->targetAmount)->toBe((string) $quote->targetAmount);

    $valid = $this->quoter->getValidQuote($quote->id, 7);
    expect($valid->id)->toBe($quote->id)
        ->and($valid->ownerId)->toBe(7);
});

test('a quote is only found by the account that created it', function () {
    $quote = $this->quoter->quoteTarget('USD', 'ZMW', '2500', ownerId: 7);

    expect($this->quoter->findQuote($quote->id, 8))->toBeNull()
        ->and($this->quoter->findQuote($quote->id, null))->toBeNull()
        ->and(fn () => $this->quoter->getValidQuote($quote->id, 8))->toThrow(FxQuoteNotFoundException::class)
        ->and($quote->toArray())->not->toHaveKey('owner_id');
});

test('it detects expired quotes', function () {
    $quote = $this->quoter->quote('USD', 'ZMW', '100.00');

    expect($quote->isExpired())->toBeFalse();

    // Fast-forward past expiry (10 minutes)
    Carbon::setTestNow(now()->addMinutes(11));

    expect($quote->isExpired())->toBeTrue();
    expect($quote->expiresInSeconds())->toBe(0);

    Carbon::setTestNow(); // reset
});

test('it throws on unknown quote id', function () {
    expect(fn () => $this->quoter->getValidQuote('00000000-0000-0000-0000-000000000000', 7))
        ->toThrow(FxQuoteNotFoundException::class);
});

test('it throws on unsupported currency pair', function () {
    expect(fn () => $this->quoter->quote('USD', 'UNKNOWN', '100.00'))
        ->toThrow(UnsupportedCurrencyPairException::class);
});

test('it exports transaction attributes matching transactions table schema', function () {
    $quote = $this->quoter->quote('USD', 'ZMW', '100.00');
    $attrs = $quote->toTransactionAttributes();

    expect($attrs)->toHaveKey('is_fx', true);
    expect($attrs)->toHaveKey('fx_rate', '27.08750000');

    $same = $this->quoter->quote('USD', 'USD', '100.00');
    expect($same->toTransactionAttributes())->toHaveKey('is_fx', false);
    expect($same->toTransactionAttributes())->toHaveKey('fx_rate', null);
});
