<?php

namespace App\Support\Paypal;

use App\Http\Controllers\Providers\PaypalController;
use App\Models\PaymentProvider;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * PayPal's own exchange rates (FX as a Service) for accounts whose PayPal
 * business account has it enabled.
 *
 * PayPal quotes from a currency the account can hold (USD, EUR, GBP...) into
 * 100+ currencies, including the mobile-money ones (ZMW, KES, GHS, NGN...).
 * Rates are cached until PayPal refreshes them. When PayPal can't quote (FX
 * not enabled on the account, PayPal unreachable), callers fall back to
 * MoneyUnify's own rates.
 *
 * @see https://developer.paypal.com/docs/checkout/fx-as-a-service/integrate/
 */
final class PaypalFxRates
{
    public const SOURCE_PAYPAL = 'paypal';

    public const SOURCE_MONEYUNIFY = 'moneyunify';

    /** The provider setting that turns PayPal rates off ("moneyunify"). */
    public const SETTING = 'fx_rates';

    /** Most rates are cached until PayPal's daily refresh, but never longer than this. */
    private const MAX_CACHE_MINUTES = 60;

    /** How long to wait before asking an account without FX enabled again. */
    private const NOT_ENABLED_MINUTES = 30;

    /** How long to wait after PayPal couldn't answer. */
    private const UNAVAILABLE_MINUTES = 2;

    private const WORKING_SCALE = 12;

    /**
     * The account's active PayPal provider that may be asked for rates.
     */
    public function providerFor(?int $ownerId): ?PaymentProvider
    {
        if ($ownerId === null) {
            return null;
        }

        return PaymentProvider::query()
            ->where('user_id', $ownerId)
            ->where('class', PaypalController::class)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->first(fn (PaymentProvider $provider): bool => self::wanted($provider) && PaypalClient::fromConfig((array) $provider->config) !== null);
    }

    /**
     * Whether the provider uses PayPal's rates (the default) rather than
     * MoneyUnify's.
     */
    public static function wanted(PaymentProvider $provider): bool
    {
        return ($provider->config[self::SETTING] ?? self::SOURCE_PAYPAL) !== self::SOURCE_MONEYUNIFY;
    }

    /**
     * Whether PayPal FX is working for this provider, as last seen.
     *
     * @return array{state: 'enabled'|'not_enabled'|'unavailable'|'unknown'|'off', message: ?string, activation_url: ?string}
     */
    public function status(PaymentProvider $provider): array
    {
        if (! self::wanted($provider)) {
            return ['state' => 'off', 'message' => 'This PayPal account is set to use MoneyUnify\'s rates.', 'activation_url' => null];
        }

        $seen = Cache::get($this->statusKey($provider));

        return is_array($seen) ? $seen : ['state' => 'unknown', 'message' => null, 'activation_url' => null];
    }

    /**
     * The rate from one currency to another, from PayPal: direct when the
     * first is a currency PayPal holds, inverted when only the second is,
     * or crossed through USD.
     */
    public function rate(PaymentProvider $provider, string $from, string $to): ?BigDecimal
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return BigDecimal::one();
        }

        if (self::holds($from)) {
            return $this->quotes($provider, $from, [$to])[$to]['rate'] ?? null;
        }

        if (self::holds($to)) {
            $inverse = $this->quotes($provider, $to, [$from])[$from]['rate'] ?? null;

            return $inverse?->isPositive() ? BigDecimal::one()->dividedBy($inverse, self::WORKING_SCALE, RoundingMode::HalfUp) : null;
        }

        $legs = $this->quotes($provider, 'USD', [$from, $to]);

        if (! isset($legs[$from], $legs[$to]) || ! $legs[$from]['rate']->isPositive()) {
            return null;
        }

        return $legs[$to]['rate']->dividedBy($legs[$from]['rate'], self::WORKING_SCALE, RoundingMode::HalfUp);
    }

    /**
     * PayPal's quotes from a held currency into each currency it can quote.
     * Currencies PayPal couldn't quote are left out.
     *
     * @param  list<string>  $currencies
     * @return array<string, array{rate: BigDecimal, fx_id: ?string, expires_at: ?string, refreshes_at: ?string}>
     */
    public function quotes(PaymentProvider $provider, string $base, array $currencies): array
    {
        $base = strtoupper($base);
        $currencies = array_values(array_unique(array_filter(array_map('strtoupper', $currencies), fn (string $currency): bool => $currency !== $base)));

        if (! self::holds($base) || $currencies === [] || ! self::wanted($provider)) {
            return [];
        }

        $found = [];
        $missing = [];

        foreach ($currencies as $currency) {
            $cached = Cache::get($this->rateKey($provider, $base, $currency));
            is_array($cached) ? $found[$currency] = self::restore($cached) : $missing[] = $currency;
        }

        $status = $this->status($provider)['state'];

        if ($missing === [] || in_array($status, ['not_enabled', 'unavailable'], true)) {
            return $found;
        }

        // Rates have a fallback, so PayPal gets one quick try.
        $client = PaypalClient::fromConfig((array) $provider->config)?->quick();

        if ($client === null) {
            return $found;
        }

        try {
            $quotes = $client->quoteExchangeRates($base, $missing);
        } catch (PaypalException $e) {
            $this->remember($provider, $e->isNotProvisioned() ? 'not_enabled' : 'unavailable', $e->getMessage(), $e->isNotProvisioned() ? $e->actionUrl : null);

            return $found;
        } catch (Throwable $e) {
            report($e);
            $this->remember($provider, 'unavailable', 'PayPal could not be reached for exchange rates.');

            return $found;
        }

        foreach ($quotes as $quote) {
            $currency = strtoupper((string) data_get($quote, 'quote_amount.currency_code', ''));
            $rate = (string) ($quote['exchange_rate'] ?? '');

            if ($currency === '' || ! is_numeric($rate) || (float) $rate <= 0) {
                continue;
            }

            $stored = [
                'rate' => $rate,
                'fx_id' => $quote['fx_id'] ?? null,
                'expires_at' => $quote['expiry_time'] ?? null,
                'refreshes_at' => $quote['rate_refresh_time'] ?? null,
            ];
            Cache::put($this->rateKey($provider, $base, $currency), $stored, $this->cacheUntil($stored['refreshes_at']));
            $found[$currency] = self::restore($stored);
            $answered = true;
        }

        if (isset($answered)) {
            $this->remember($provider, 'enabled', null);
        } elseif (! in_array($status, ['enabled'], true)) {
            $this->remember($provider, 'unavailable', 'PayPal returned no exchange rates.');
        }

        return $found;
    }

    /**
     * Forget what PayPal said, e.g. after the account's credentials change.
     */
    public function forget(PaymentProvider $provider): void
    {
        Cache::forget($this->statusKey($provider));
    }

    /**
     * PayPal can hold (and so quote from) this currency.
     */
    public static function holds(string $currency): bool
    {
        return in_array(strtoupper($currency), PaypalController::SUPPORTED_CURRENCIES, true);
    }

    /**
     * @param  array{rate: string, fx_id: ?string, expires_at: ?string, refreshes_at: ?string}  $stored
     * @return array{rate: BigDecimal, fx_id: ?string, expires_at: ?string, refreshes_at: ?string}
     */
    private static function restore(array $stored): array
    {
        return [...$stored, 'rate' => BigDecimal::of($stored['rate'])];
    }

    private function cacheUntil(?string $refreshesAt): CarbonImmutable
    {
        $latest = now()->toImmutable()->addMinutes(self::MAX_CACHE_MINUTES);

        try {
            $refresh = $refreshesAt ? CarbonImmutable::parse($refreshesAt) : null;
        } catch (Throwable) {
            $refresh = null;
        }

        return $refresh && $refresh->isFuture() && $refresh->lt($latest) ? $refresh : $latest;
    }

    private function remember(PaymentProvider $provider, string $state, ?string $message, ?string $activationUrl = null): void
    {
        $minutes = match ($state) {
            'not_enabled' => self::NOT_ENABLED_MINUTES,
            'unavailable' => self::UNAVAILABLE_MINUTES,
            default => self::MAX_CACHE_MINUTES,
        };

        Cache::put($this->statusKey($provider), ['state' => $state, 'message' => $message, 'activation_url' => $activationUrl], now()->addMinutes($minutes));
    }

    private function rateKey(PaymentProvider $provider, string $base, string $currency): string
    {
        return "paypal-fx:{$provider->id}:{$base}:{$currency}";
    }

    private function statusKey(PaymentProvider $provider): string
    {
        return "paypal-fx:{$provider->id}:status";
    }
}
