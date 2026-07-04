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
     * ISO-3166 alpha-2 country code => [name, currency, calling_code, alpha3].
     *
     * @var array<string, array{name: string, currency: string, calling_code: string, alpha3: string}>
     */
    public const MARKETS = [
        'ZM' => ['name' => 'Zambia', 'currency' => 'ZMW', 'calling_code' => '260', 'alpha3' => 'ZMB'],
        'MW' => ['name' => 'Malawi', 'currency' => 'MWK', 'calling_code' => '265', 'alpha3' => 'MWI'],
        'UG' => ['name' => 'Uganda', 'currency' => 'UGX', 'calling_code' => '256', 'alpha3' => 'UGA'],
        'GH' => ['name' => 'Ghana', 'currency' => 'GHS', 'calling_code' => '233', 'alpha3' => 'GHA'],
        'RW' => ['name' => 'Rwanda', 'currency' => 'RWF', 'calling_code' => '250', 'alpha3' => 'RWA'],
        'CM' => ['name' => 'Cameroon', 'currency' => 'XAF', 'calling_code' => '237', 'alpha3' => 'CMR'],
        'CI' => ['name' => "Côte d'Ivoire", 'currency' => 'XOF', 'calling_code' => '225', 'alpha3' => 'CIV'],
        'BJ' => ['name' => 'Benin', 'currency' => 'XOF', 'calling_code' => '229', 'alpha3' => 'BEN'],
        'GN' => ['name' => 'Guinea', 'currency' => 'GNF', 'calling_code' => '224', 'alpha3' => 'GIN'],
        'GW' => ['name' => 'Guinea-Bissau', 'currency' => 'XOF', 'calling_code' => '245', 'alpha3' => 'GNB'],
        'LR' => ['name' => 'Liberia', 'currency' => 'LRD', 'calling_code' => '231', 'alpha3' => 'LBR'],
        'CG' => ['name' => 'Congo-Brazzaville', 'currency' => 'XAF', 'calling_code' => '242', 'alpha3' => 'COG'],
        'CD' => ['name' => 'DR Congo', 'currency' => 'CDF', 'calling_code' => '243', 'alpha3' => 'COD'],
        'GA' => ['name' => 'Gabon', 'currency' => 'XAF', 'calling_code' => '241', 'alpha3' => 'GAB'],
        'TD' => ['name' => 'Chad', 'currency' => 'XAF', 'calling_code' => '235', 'alpha3' => 'TCD'],
        'NE' => ['name' => 'Niger', 'currency' => 'XOF', 'calling_code' => '227', 'alpha3' => 'NER'],
        'NG' => ['name' => 'Nigeria', 'currency' => 'NGN', 'calling_code' => '234', 'alpha3' => 'NGA'],
        'KE' => ['name' => 'Kenya', 'currency' => 'KES', 'calling_code' => '254', 'alpha3' => 'KEN'],
        'TZ' => ['name' => 'Tanzania', 'currency' => 'TZS', 'calling_code' => '255', 'alpha3' => 'TZA'],
        'MZ' => ['name' => 'Mozambique', 'currency' => 'MZN', 'calling_code' => '258', 'alpha3' => 'MOZ'],
        'LS' => ['name' => 'Lesotho', 'currency' => 'LSL', 'calling_code' => '266', 'alpha3' => 'LSO'],
        'MG' => ['name' => 'Madagascar', 'currency' => 'MGA', 'calling_code' => '261', 'alpha3' => 'MDG'],
        'SC' => ['name' => 'Seychelles', 'currency' => 'SCR', 'calling_code' => '248', 'alpha3' => 'SYC'],
        'ZA' => ['name' => 'South Africa', 'currency' => 'ZAR', 'calling_code' => '27', 'alpha3' => 'ZAF'],
        'SZ' => ['name' => 'Eswatini', 'currency' => 'SZL', 'calling_code' => '268', 'alpha3' => 'SWZ'],
        'SS' => ['name' => 'South Sudan', 'currency' => 'SSP', 'calling_code' => '211', 'alpha3' => 'SSD'],
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
     * The ISO-3166 alpha-3 country code (e.g. ZM => "ZMB").
     */
    public static function alpha3(string $country): ?string
    {
        return self::MARKETS[strtoupper($country)]['alpha3'] ?? null;
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
