<?php

namespace App\Support\Merchants;

use App\Contracts\PayoutProviderInterface;
use App\Contracts\RefundsPayments;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\Currency;
use App\Support\Fx\FxQuoter;
use App\Support\Market;
use App\Support\ProviderRouter;
use Illuminate\Support\Collection;

/**
 * Which currencies an aggregator's customers can pay in, and which currencies
 * its merchants can be settled in, given the providers it has set up.
 */
class MerchantCurrencies
{
    public function __construct(private readonly FxQuoter $fx) {}

    /**
     * Currencies a customer can pay in: what the account's PayPal collects and
     * FX can convert.
     *
     * @return list<string>
     */
    public function payCurrencies(User $user): array
    {
        $convertible = $this->fx->getRateProvider()->supportedCurrencies();

        return $this->providers($user, RefundsPayments::class)
            ->flatMap(fn (PaymentProvider $provider): array => defined($provider->class.'::SUPPORTED_CURRENCIES') ? $provider->class::SUPPORTED_CURRENCIES : [])
            ->intersect($convertible)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Currencies a merchant in this country can be settled in: those an active
     * payout provider serving the country pays out in, and FX can convert to.
     *
     * @return list<string>
     */
    public function settlementCurrencies(User $user, string $country): array
    {
        $country = strtoupper($country);
        $convertible = $this->fx->getRateProvider()->supportedCurrencies();
        $local = Market::currency($country);

        $currencies = $this->providers($user, PayoutProviderInterface::class)
            ->filter(fn (PaymentProvider $provider): bool => in_array($country, $this->countriesOf($provider), true))
            ->flatMap(fn (PaymentProvider $provider): array => defined($provider->class.'::SUPPORTED_CURRENCIES')
                ? array_values(array_filter($provider->class::SUPPORTED_CURRENCIES, fn (string $currency): bool => Currency::isSupportedBy($provider->class, $country, $currency)))
                : array_filter([$local]))
            ->filter(fn (string $currency): bool => in_array($currency, $convertible, true))
            ->unique()
            ->values();

        // The local currency first: it's what the merchant's wallet holds.
        return $currencies->sortBy(fn (string $currency): int => $currency === $local ? 0 : 1)->values()->all();
    }

    /**
     * @return Collection<int, PaymentProvider>
     */
    private function providers(User $user, string $capability): Collection
    {
        return $user->paymentProviders()->where('is_active', true)->get()
            ->filter(fn (PaymentProvider $provider): bool => class_exists($provider->class) && ProviderRouter::supports($provider, $capability));
    }

    /**
     * @return list<string>
     */
    private function countriesOf(PaymentProvider $provider): array
    {
        $countries = (array) ($provider->config['supported_countries'] ?? (defined($provider->class.'::SUPPORTED_COUNTRIES') ? $provider->class::SUPPORTED_COUNTRIES : []));

        return array_map(fn ($code): string => strtoupper((string) $code), $countries);
    }
}
