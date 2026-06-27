<?php

namespace App\Support;

/**
 * Central registry of the markets (countries) the switch knows about, with each
 * country's display name and ISO-4217 currency. Drivers declare which of these
 * they support; the currency is always resolved from here so every provider
 * uses the correct, consistent currency per country.
 */
class Market
{
    /**
     * ISO-3166 alpha-2 country code => [name, currency, calling_code].
     *
     * @var array<string, array{name: string, currency: string, calling_code: string}>
     */
    public const MARKETS = [
        'ZM' => ['name' => 'Zambia', 'currency' => 'ZMW', 'calling_code' => '260'],
        'MW' => ['name' => 'Malawi', 'currency' => 'MWK', 'calling_code' => '265'],
        'UG' => ['name' => 'Uganda', 'currency' => 'UGX', 'calling_code' => '256'],
        'GH' => ['name' => 'Ghana', 'currency' => 'GHS', 'calling_code' => '233'],
        'RW' => ['name' => 'Rwanda', 'currency' => 'RWF', 'calling_code' => '250'],
        'CM' => ['name' => 'Cameroon', 'currency' => 'XAF', 'calling_code' => '237'],
        'CI' => ['name' => "Côte d'Ivoire", 'currency' => 'XOF', 'calling_code' => '225'],
        'BJ' => ['name' => 'Benin', 'currency' => 'XOF', 'calling_code' => '229'],
        'GN' => ['name' => 'Guinea', 'currency' => 'GNF', 'calling_code' => '224'],
        'GW' => ['name' => 'Guinea-Bissau', 'currency' => 'XOF', 'calling_code' => '245'],
        'LR' => ['name' => 'Liberia', 'currency' => 'LRD', 'calling_code' => '231'],
        'CG' => ['name' => 'Congo-Brazzaville', 'currency' => 'XAF', 'calling_code' => '242'],
        'CD' => ['name' => 'DR Congo', 'currency' => 'CDF', 'calling_code' => '243'],
        'GA' => ['name' => 'Gabon', 'currency' => 'XAF', 'calling_code' => '241'],
        'TD' => ['name' => 'Chad', 'currency' => 'XAF', 'calling_code' => '235'],
        'NE' => ['name' => 'Niger', 'currency' => 'XOF', 'calling_code' => '227'],
        'NG' => ['name' => 'Nigeria', 'currency' => 'NGN', 'calling_code' => '234'],
        'KE' => ['name' => 'Kenya', 'currency' => 'KES', 'calling_code' => '254'],
        'TZ' => ['name' => 'Tanzania', 'currency' => 'TZS', 'calling_code' => '255'],
        'MG' => ['name' => 'Madagascar', 'currency' => 'MGA', 'calling_code' => '261'],
        'SC' => ['name' => 'Seychelles', 'currency' => 'SCR', 'calling_code' => '248'],
        'ZA' => ['name' => 'South Africa', 'currency' => 'ZAR', 'calling_code' => '27'],
        'SZ' => ['name' => 'Eswatini', 'currency' => 'SZL', 'calling_code' => '268'],
        'SS' => ['name' => 'South Sudan', 'currency' => 'SSP', 'calling_code' => '211'],
    ];

    /**
     * The currency for a country, or null if the country isn't a known market.
     */
    public static function currency(string $country): ?string
    {
        return self::MARKETS[strtoupper($country)]['currency'] ?? null;
    }

    /**
     * The international calling code for a country (e.g. ZM => "260").
     */
    public static function callingCode(string $country): ?string
    {
        return self::MARKETS[strtoupper($country)]['calling_code'] ?? null;
    }

    /**
     * Best-effort reverse lookup: the first country using a currency. Only used
     * as a fallback for legacy transactions that predate the stored country.
     */
    public static function countryForCurrency(?string $currency): ?string
    {
        foreach (self::MARKETS as $code => $market) {
            if ($market['currency'] === $currency) {
                return $code;
            }
        }

        return null;
    }

    /**
     * The display name for a country (falls back to the code).
     */
    public static function name(string $country): string
    {
        return self::MARKETS[strtoupper($country)]['name'] ?? strtoupper($country);
    }

    /**
     * Whether a country is a known market.
     */
    public static function isKnown(string $country): bool
    {
        return isset(self::MARKETS[strtoupper($country)]);
    }

    /**
     * All known country codes.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::MARKETS);
    }

    /**
     * Build option rows ({code, name, currency}) for the given country codes,
     * for rendering selectable markets in the dashboard.
     *
     * @param  list<string>  $countries
     * @return list<array{code: string, name: string, currency: string}>
     */
    public static function options(array $countries): array
    {
        $options = [];

        foreach ($countries as $code) {
            $code = strtoupper($code);
            if (isset(self::MARKETS[$code])) {
                $options[] = [
                    'code' => $code,
                    'name' => self::MARKETS[$code]['name'],
                    'currency' => self::MARKETS[$code]['currency'],
                ];
            }
        }

        return $options;
    }
}
