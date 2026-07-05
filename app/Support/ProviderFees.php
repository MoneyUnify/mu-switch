<?php

namespace App\Support;

use App\Http\Controllers\Providers\FlutterwaveController;
use App\Models\PaymentProvider;

/**
 * Provider fee logic: reads a provider's fee schedule, computes the collection
 * and settlement fees for an amount, and extracts the actual collection fee from
 * a charge response for providers that return one.
 *
 * A schedule is:
 *   [
 *     'collection' => ['percent' => 1.0, 'flat' => 0.0, 'min' => 0.0, 'max' => null],
 *     'settlement' => ['tiers' => [['max' => 1000, 'fee' => 8.5]], 'default' => 8.5],
 *   ]
 */
class ProviderFees
{
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
     * The collection fee for an amount under a schedule.
     *
     * @param  array<string, mixed>  $schedule
     */
    public static function collectionFee(array $schedule, float $amount): float
    {
        $collection = $schedule['collection'] ?? [];

        $fee = ($amount * (float) ($collection['percent'] ?? 0)) / 100 + (float) ($collection['flat'] ?? 0);

        if (isset($collection['min'])) {
            $fee = max($fee, (float) $collection['min']);
        }

        if (isset($collection['max']) && $collection['max'] !== null) {
            $fee = min($fee, (float) $collection['max']);
        }

        return round($fee, 4);
    }

    /**
     * The settlement fee for an amount under a schedule (tiered by amount band,
     * falling back to the schedule default).
     *
     * @param  array<string, mixed>  $schedule
     */
    public static function settlementFee(array $schedule, float $amount): float
    {
        $settlement = $schedule['settlement'] ?? [];

        foreach ($settlement['tiers'] ?? [] as $tier) {
            if ($amount <= (float) ($tier['max'] ?? INF)) {
                return round((float) ($tier['fee'] ?? 0), 4);
            }
        }

        return round((float) ($settlement['default'] ?? 0), 4);
    }

    /**
     * The actual collection fee a provider returned in its charge response, or
     * null when this provider doesn't report one.
     */
    public static function actualCollectionFee(?string $class, mixed $providerResponse): ?float
    {
        $path = self::ACTUAL_FEE_PATHS[$class] ?? null;
        if (! $path || $providerResponse === null) {
            return null;
        }

        $data = is_string($providerResponse) ? json_decode($providerResponse, true) : $providerResponse;
        if (! is_array($data)) {
            return null;
        }

        $value = data_get($data, $path);

        return is_numeric($value) ? round((float) $value, 4) : null;
    }
}
