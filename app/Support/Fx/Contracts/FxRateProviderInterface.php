<?php

namespace App\Support\Fx\Contracts;

use Brick\Math\BigDecimal;

interface FxRateProviderInterface
{
    /**
     * Get the mid-market exchange rate between two currencies (1 unit of $fromCurrency = ? units of $toCurrency).
     */
    public function getRate(string $fromCurrency, string $toCurrency): ?BigDecimal;

    /**
     * Determine whether an exchange rate is supported between two currencies.
     */
    public function supports(string $fromCurrency, string $toCurrency): bool;

    /**
     * Get all available rates relative to a given base currency.
     *
     * @return array<string, float>
     */
    public function allRates(string $baseCurrency = 'USD'): array;

    /**
     * Get the list of all supported currency codes.
     *
     * @return list<string>
     */
    public function supportedCurrencies(): array;
}
