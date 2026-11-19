<?php

namespace App\Support;

use App\Http\Controllers\Providers\FlutterwaveController;
use App\Models\PaymentProvider;
use App\Support\Fees\FeeCalculator;

/**
 * Provider fee logic: reads a provider's fee schedule, computes the collection
 * and settlement fees for an amount (via {@see FeeCalculator}), and extracts
 * the actual collection fee from a charge response for providers that return one.
 * See FeeCalculator for the schedule format.
 */
class ProviderFees
{
    /**
     * Currency assumed when none is given (two decimal places).
     */
    private const DEFAULT_CURRENCY = 'USD';

    /**
     * Response paths (dot notation) where a provider returns the actual fee it
     * charged for a collection, keyed by driver class.
     *
     * @var array<class-string, string>
     */
    private const ACTUAL_FEE_PATHS = [
        FlutterwaveController::class => 'data.app_fee',
    ];

    /**
     * The fee schedule for a provider — a per-provider `fee_schedule` config
     * override, otherwise the driver's FEE_SCHEDULE constant.
     *
     * @return array<string, mixed>|null
     */
    public static function scheduleFor(PaymentProvider $provider): ?array
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : ($provider->config ?? []);

        if (is_array($config['fee_schedule'] ?? null)) {
            return $config['fee_schedule'];
        }

        $class = $provider->class;

        return $class && defined($class.'::FEE_SCHEDULE') ? $class::FEE_SCHEDULE : null;
    }

    /**
     * The collection fee for an amount under a schedule, rounded to the currency.
     *
     * @param  array<string, mixed>  $schedule
     */
    public static function collectionFee(array $schedule, float $amount, ?string $currency = null): float
    {
        $calculator = new FeeCalculator($schedule, $currency ?? self::DEFAULT_CURRENCY);

        return $calculator->collectionFee(FeeCalculator::money($amount))->toFloat();
    }

    /**
     * The settlement fee for an amount under a schedule (tiered by amount band,
     * falling back to the schedule default), rounded to the currency.
     *
     * @param  array<string, mixed>  $schedule
     */
    public static function settlementFee(array $schedule, float $amount, ?string $currency = null): float
    {
        $calculator = new FeeCalculator($schedule, $currency ?? self::DEFAULT_CURRENCY);

        return $calculator->settlementFee(FeeCalculator::money($amount))->toFloat();
    }

    /**
     * The payout fee for an amount, or null when the schedule doesn't price payouts.
     *
     * @param  array<string, mixed>  $schedule
     */
    public static function payoutFee(array $schedule, float $amount, ?string $currency = null): ?float
    {
        $calculator = new FeeCalculator($schedule, $currency ?? self::DEFAULT_CURRENCY);

        return $calculator->payoutFee(FeeCalculator::money($amount))?->toFloat();
    }

    /**
     * The actual collection fee a provider reported for a payment, or null when
     * it doesn't report one. Drivers may define a static
     * `actualCollectionFee(array $response, string $currency): ?string`;
     * otherwise a known response path is read.
     */
    public static function actualCollectionFee(?string $class, mixed $providerResponse, ?string $currency = null): ?float
    {
        if (! $class || $providerResponse === null) {
            return null;
        }

        $data = is_string($providerResponse) ? json_decode($providerResponse, true) : $providerResponse;
        if (! is_array($data)) {
            return null;
        }

        if (method_exists($class, 'actualCollectionFee')) {
            $fee = $class::actualCollectionFee($data, (string) $currency);

            return $fee === null ? null : FeeCalculator::money($fee)->toFloat();
        }

        $path = self::ACTUAL_FEE_PATHS[$class] ?? null;
        $value = $path ? data_get($data, $path) : null;

        return is_numeric($value) ? round((float) $value, 4) : null;
    }
}
