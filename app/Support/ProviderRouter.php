<?php

namespace App\Support;

use App\Contracts\PaymentProviderInterface;
use App\Contracts\PayoutProviderInterface;
use App\Exceptions\NoEligibleProviderException;
use App\Http\Controllers\Providers\PaypalController;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\Fx\Contracts\FxRateProviderInterface;
use Illuminate\Support\Collection;

/**
 * Decides which of an account's providers can take a payment, and in what
 * order the switch tries them. Shared by payments and quotes so a quote
 * always reflects how the payment would actually route.
 */
class ProviderRouter
{
    /**
     * The eligible providers, in the order they will be tried.
     *
     * @param  class-string  $capability  The driver contract needed, e.g. collections or payouts.
     * @return Collection<int, PaymentProvider>
     *
     * @throws NoEligibleProviderException
     */
    public static function route(?User $user, string $country, string $currency, float $amount, string $capability = PaymentProviderInterface::class, bool $allowFx = false): Collection
    {
        $providers = $user ? $user->paymentProviders : collect();

        if ($providers->isEmpty()) {
            throw new NoEligibleProviderException('Providers not configured yet. Please configure at least 1 (one) provider');
        }

        $country = strtoupper($country);
        $eligible = $providers->filter(fn (PaymentProvider $provider): bool => $provider->is_active
            && self::supports($provider, $capability)
            && self::servesCountry($provider, $country));

        if ($eligible->isEmpty()) {
            $kind = $capability === PayoutProviderInterface::class ? 'payout providers' : 'providers';

            throw new NoEligibleProviderException("No active {$kind} support the requested country");
        }

        // Only providers that can charge the currency (or convert via FX when allowed),
        // so a fallback never silently charges the same amount in another currency.
        $eligible = $eligible->filter(fn (PaymentProvider $provider): bool => Currency::isSupportedBy($provider->class, $country, $currency)
            || ($allowFx && self::supportsFx($provider->class, $currency)));

        if ($eligible->isEmpty()) {
            throw new NoEligibleProviderException("No active providers support {$currency} payments in {$country}");
        }

        // Under the "cost aware" policy, try the cheapest provider first.
        return $user?->feePolicy() === 'cost_aware'
            ? self::orderByCost($eligible, $amount, $currency)
            : $eligible->values();
    }

    /**
     * Whether a provider driver can convert the requested currency via FX.
     */
    public static function supportsFx(string $driverClass, string $currency): bool
    {
        if ($driverClass === PaypalController::class) {
            $base = config('moneyunify_paypal.fx.base_currency', 'USD');

            return app(FxRateProviderInterface::class)->supports($currency, $base);
        }

        return false;
    }

    /**
     * Whether the provider's driver implements the needed contract. Unknown
     * classes are kept so the caller can report the missing driver.
     *
     * @param  class-string  $capability
     */
    public static function supports(PaymentProvider $provider, string $capability): bool
    {
        if (! class_exists($provider->class)) {
            return true;
        }

        if (! is_subclass_of($provider->class, $capability) || ! DriverRegistry::isAvailable($provider->class)) {
            return false;
        }

        // A payout driver may need extra settings before it can pay out (e.g. Lenco's account ID).
        if ($capability === PayoutProviderInterface::class && method_exists($provider->class, 'payoutReady')) {
            $config = is_string($provider->config) ? (json_decode($provider->config, true) ?? []) : (array) ($provider->config ?? []);

            return $provider->class::payoutReady($config);
        }

        return true;
    }

    /**
     * Whether a provider is configured for the country (no list means any).
     */
    private static function servesCountry(PaymentProvider $provider, string $country): bool
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;
        $countries = $config['supported_countries'] ?? null;

        if ($countries === null) {
            return true;
        }

        $countries = is_array($countries) ? $countries : array_map('trim', explode(',', (string) $countries));

        return in_array($country, array_map('strtoupper', $countries), true);
    }

    /**
     * Cheapest total fee first. Providers without a fee schedule sort last but
     * keep their relative order, so pricing never silently drops one.
     *
     * @param  Collection<int, PaymentProvider>  $providers
     * @return Collection<int, PaymentProvider>
     */
    private static function orderByCost(Collection $providers, float $amount, string $currency): Collection
    {
        return $providers
            ->values()
            ->map(function (PaymentProvider $provider, int $index) use ($amount, $currency): array {
                $schedule = ProviderFees::scheduleFor($provider);
                $cost = $schedule === null
                    ? INF
                    : ProviderFees::collectionFee($schedule, $amount, $currency) + ProviderFees::settlementFee($schedule, $amount, $currency);

                return ['provider' => $provider, 'cost' => $cost, 'index' => $index];
            })
            ->sortBy([['cost', 'asc'], ['index', 'asc']])
            ->pluck('provider')
            ->values();
    }
}
