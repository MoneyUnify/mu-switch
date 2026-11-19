<?php

namespace App\Support\Fees;

use App\Support\Currency;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Exact fee maths for one provider's fee schedule in one currency.
 *
 * Amounts use decimal arithmetic (never floats) and each fee is rounded
 * half-up to the currency's minor unit, as providers bill them.
 *
 * A schedule is:
 *   [
 *     'collection' => ['percent' => 3.49, 'flat' => 0.49 | ['USD' => 0.49, 'default' => 0.30], 'min' => 0, 'max' => null],
 *     'settlement' => ['tiers' => [['max' => 1000, 'fee' => 8.5]], 'default' => 8.5],
 *     'payout' => ['percent' => 1.0, 'flat' => 0, 'min' => null, 'max' => null], // optional
 *   ]
 */
final class FeeCalculator
{
    /**
     * Extra digits kept while calculating, before rounding to the currency.
     */
    private const WORKING_SCALE = 10;

    private readonly int $scale;

    /**
     * @param  array<string, mixed>  $schedule
     */
    public function __construct(private readonly array $schedule, private readonly string $currency)
    {
        $this->scale = Currency::decimals($currency);
    }

    /**
     * Parse an amount into an exact decimal.
     */
    public static function money(BigDecimal|int|float|string $amount): BigDecimal
    {
        if ($amount instanceof BigDecimal) {
            return $amount;
        }

        return BigDecimal::of(is_float($amount) ? number_format($amount, self::WORKING_SCALE, '.', '') : (string) $amount);
    }

    /**
     * The fees when the payer is charged exactly this amount (the merchant pays them).
     */
    public function forPayerAmount(BigDecimal|int|float|string $payerPays): FeeBreakdown
    {
        $gross = $this->round(self::money($payerPays));

        return new FeeBreakdown($this->currency, $gross, $this->collectionFee($gross), $this->settlementFee($gross));
    }

    /**
     * The smallest charge that leaves the merchant at least this amount (the payer pays the fees).
     */
    public function forMerchantAmount(BigDecimal|int|float|string $merchantReceives): FeeBreakdown
    {
        $target = $this->round(self::money($merchantReceives));

        // Search whole minor units for the lowest charge that covers the fees.
        $low = $this->toMinorUnits($target);
        $high = $low;

        for ($i = 0; ! $this->covers($high, $target); $i++) {
            if ($i >= 64) {
                throw new InvalidArgumentException('Fees exceed any charge for this amount.');
            }

            $high = $high->multipliedBy(2)->plus(1);
        }

        while ($low->isLessThan($high)) {
            $middle = $low->plus($high)->quotient(2);

            if ($this->covers($middle, $target)) {
                $high = $middle;
            } else {
                $low = $middle->plus(1);
            }
        }

        return $this->forPayerAmount($this->fromMinorUnits($high));
    }

    /**
     * The provider's fee for collecting this amount, rounded.
     */
    public function collectionFee(BigDecimal $gross): BigDecimal
    {
        return $this->percentageFee($this->schedule['collection'] ?? [], $gross);
    }

    /**
     * The provider's fee for paying this amount out, or null when the
     * schedule doesn't price payouts.
     */
    public function payoutFee(BigDecimal $amount): ?BigDecimal
    {
        $payout = $this->schedule['payout'] ?? null;

        return is_array($payout) ? $this->percentageFee($payout, $amount) : null;
    }

    /**
     * A percentage plus fixed fee, within its min and max, rounded.
     *
     * @param  array<string, mixed>  $section
     */
    private function percentageFee(array $section, BigDecimal $amount): BigDecimal
    {
        $fee = $amount
            ->multipliedBy(self::money($section['percent'] ?? 0))
            ->dividedBy(100, self::WORKING_SCALE, RoundingMode::HalfUp)
            ->plus($this->forCurrency($section['flat'] ?? 0));

        if (($min = $this->forCurrency($section['min'] ?? null)) !== null && $fee->isLessThan($min)) {
            $fee = $min;
        }

        if (($max = $this->forCurrency($section['max'] ?? null)) !== null && $fee->isGreaterThan($max)) {
            $fee = $max;
        }

        return $this->round($fee);
    }

    /**
     * The settlement fee for the amount's tier, else the schedule default.
     */
    public function settlementFee(BigDecimal $gross): BigDecimal
    {
        $settlement = $this->schedule['settlement'] ?? [];

        foreach ($settlement['tiers'] ?? [] as $tier) {
            $max = $tier['max'] ?? null;

            if ($max === null || $gross->isLessThanOrEqualTo(self::money($max))) {
                return $this->round($this->forCurrency($tier['fee'] ?? 0));
            }
        }

        return $this->round($this->forCurrency($settlement['default'] ?? 0));
    }

    /**
     * A schedule value, which may be one number or a map of currency => number.
     */
    private function forCurrency(mixed $value): ?BigDecimal
    {
        if (is_array($value)) {
            $value = $value[strtoupper($this->currency)] ?? $value['default'] ?? null;
        }

        return $value === null ? null : self::money($value);
    }

    private function covers(BigInteger $minorUnits, BigDecimal $target): bool
    {
        return $this->forPayerAmount($this->fromMinorUnits($minorUnits))->merchantReceives()->isGreaterThanOrEqualTo($target);
    }

    private function round(BigDecimal $amount): BigDecimal
    {
        return $amount->toScale($this->scale, RoundingMode::HalfUp);
    }

    private function toMinorUnits(BigDecimal $amount): BigInteger
    {
        return $amount->withPointMovedRight($this->scale)->toBigInteger();
    }

    private function fromMinorUnits(BigInteger $minorUnits): BigDecimal
    {
        return BigDecimal::of($minorUnits)->withPointMovedLeft($this->scale);
    }
}
