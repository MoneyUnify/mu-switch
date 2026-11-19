<?php

namespace App\Support;

use App\Models\PaymentProvider;
use Illuminate\Support\Collection;

/**
 * The currencies a volume card shows: always at least six, so an account
 * sees the currencies it works in even before they have volume. Currencies
 * with volume come first (busiest first), then the ones transacted most
 * recently, then the ones its providers support, then common defaults. USD
 * is always among them.
 */
class CurrencyVolumes
{
    public const SHOWN = 6;

    /** Used when an account has too few currencies of its own. */
    public const DEFAULTS = ['USD', 'ZMW', 'KES', 'GHS', 'UGX', 'NGN'];

    /**
     * @param  list<array{currency: string, amount: float, count: int}>  $volumes  Currencies with volume, busiest first.
     * @param  iterable<string>  ...$candidates  More currencies to fill with, in order of preference.
     * @return list<array{currency: string, amount: float, count: int}>
     */
    public static function padded(array $volumes, iterable ...$candidates): array
    {
        $withVolume = collect($volumes)->keyBy('currency');

        $codes = $withVolume->keys();

        foreach ([...$candidates, self::DEFAULTS] as $list) {
            $codes = $codes->concat(collect($list)->filter()->map(fn (string $code): string => strtoupper($code)));
        }

        // Every currency with volume, then enough others to make six.
        $shown = $codes->unique()->values()->take(max(self::SHOWN, $withVolume->count()));

        if (! $shown->contains('USD')) {
            $shown = $shown->count() > $withVolume->count()
                ? $shown->slice(0, -1)->push('USD')
                : $shown->push('USD');
        }

        return $shown->map(fn (string $code): array => $withVolume->get($code) ?? [
            'currency' => $code,
            'amount' => 0.0,
            'count' => 0,
        ])->values()->all();
    }

    /**
     * The currencies of the markets an account's providers serve.
     *
     * @param  Collection<int, PaymentProvider>  $providers
     * @return list<string>
     */
    public static function supportedBy(Collection $providers): array
    {
        return $providers
            ->flatMap(fn (PaymentProvider $provider): array => (array) ($provider->config['supported_countries'] ?? []))
            ->map(fn (mixed $country): ?string => Market::currency((string) $country))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
