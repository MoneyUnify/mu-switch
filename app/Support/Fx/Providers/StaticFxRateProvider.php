<?php

namespace App\Support\Fx\Providers;

use App\Support\Fees\FeeCalculator;
use App\Support\Fx\Contracts\FxRateProviderInterface;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Provides static exchange rates from configuration. Designed for sandbox
 * testing and local development with Africa mobile-money and PayPal corridors.
 */
class StaticFxRateProvider implements FxRateProviderInterface
{
    /**
     * Working precision for intermediate FX division calculations.
     */
    private const CALCULATION_SCALE = 10;

    /**
     * @var array<string, float|int|string>
     */
    private array $rates;

    /**
     * @var array<string, float|int|string>
     */
    private array $customPairs;

    private string $baseCurrency;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->rates = array_change_key_case((array) ($config['rates'] ?? config('moneyunify_paypal.fx.rates', [])), CASE_UPPER);
        $this->customPairs = array_change_key_case((array) ($config['custom_pairs'] ?? config('moneyunify_paypal.fx.custom_pairs', [])), CASE_UPPER);
        $this->baseCurrency = strtoupper((string) ($config['base_currency'] ?? config('moneyunify_paypal.fx.base_currency', 'USD')));
    }

    public function getRate(string $fromCurrency, string $toCurrency): ?BigDecimal
    {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === '' || $to === '') {
            return null;
        }

        if ($from === $to) {
            return BigDecimal::one();
        }

        // 1. Direct custom pair override (e.g. 'USD_ZMW')
        $pairKey = "{$from}_{$to}";
        if (isset($this->customPairs[$pairKey])) {
            return FeeCalculator::money((string) $this->customPairs[$pairKey]);
        }

        // Inverse custom pair (e.g. 'ZMW_USD' when 'USD_ZMW' exists)
        $inverseKey = "{$to}_{$from}";
        if (isset($this->customPairs[$inverseKey])) {
            $inverseRate = FeeCalculator::money((string) $this->customPairs[$inverseKey]);
            if ($inverseRate->isPositive()) {
                return BigDecimal::one()->dividedBy($inverseRate, self::CALCULATION_SCALE, RoundingMode::HalfUp);
            }
        }

        // 2. Standard rates via base currency (usually USD)
        if (! isset($this->rates[$from]) || ! isset($this->rates[$to])) {
            return null;
        }

        $fromRateAgainstBase = FeeCalculator::money((string) $this->rates[$from]);
        $toRateAgainstBase = FeeCalculator::money((string) $this->rates[$to]);

        if (! $fromRateAgainstBase->isPositive() || ! $toRateAgainstBase->isPositive()) {
            return null;
        }

        // If from is base (e.g. USD -> ZMW), rate is simply toRateAgainstBase
        if ($from === $this->baseCurrency) {
            return $toRateAgainstBase;
        }

        // If to is base (e.g. ZMW -> USD), rate is 1 / fromRateAgainstBase
        if ($to === $this->baseCurrency) {
            return BigDecimal::one()->dividedBy($fromRateAgainstBase, self::CALCULATION_SCALE, RoundingMode::HalfUp);
        }

        // Cross-rate via base (e.g. EUR -> ZMW = (USD -> ZMW) / (USD -> EUR))
        return $toRateAgainstBase->dividedBy($fromRateAgainstBase, self::CALCULATION_SCALE, RoundingMode::HalfUp);
    }

    public function supports(string $fromCurrency, string $toCurrency): bool
    {
        return $this->getRate($fromCurrency, $toCurrency) !== null;
    }

    public function allRates(string $baseCurrency = 'USD'): array
    {
        $base = strtoupper(trim($baseCurrency));
        $result = [];

        foreach ($this->supportedCurrencies() as $currency) {
            $rate = $this->getRate($base, $currency);
            if ($rate !== null) {
                $result[$currency] = $rate->toFloat();
            }
        }

        return $result;
    }

    public function supportedCurrencies(): array
    {
        $currencies = array_keys($this->rates);

        foreach (array_keys($this->customPairs) as $pair) {
            $parts = explode('_', $pair);
            if (count($parts) === 2) {
                $currencies[] = $parts[0];
                $currencies[] = $parts[1];
            }
        }

        return array_values(array_unique(array_filter($currencies)));
    }
}
