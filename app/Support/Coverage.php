<?php

namespace App\Support;

use App\Contracts\PaymentProviderInterface;

/**
 * Aggregates the markets the switch can serve across every built-in provider
 * driver — the single source of truth for "supported countries" surfaces such
 * as the landing page and the coverage docs.
 */
class Coverage
{
    /**
     * The distinct countries supported by at least one driver, each with its
     * ISO alpha-2 code and display name, sorted by name.
     *
     * @return list<array{code: string, name: string}>
     */
    public static function countries(): array
    {
        $codes = [];

        foreach (self::driverClasses() as $class) {
            if (defined($class.'::SUPPORTED_COUNTRIES')) {
                foreach ($class::SUPPORTED_COUNTRIES as $code) {
                    $codes[strtoupper($code)] = true;
                }
            }
        }

        $countries = [];
        foreach (array_keys($codes) as $code) {
            if ($name = Market::name($code)) {
                $countries[] = ['code' => $code, 'name' => $name];
            }
        }

        usort($countries, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $countries;
    }

    /**
     * Headline coverage figures for value-proposition surfaces.
     *
     * @return array{countries: int, providers: int, currencies: int}
     */
    public static function stats(): array
    {
        $countries = self::countries();

        $currencies = [];
        foreach ($countries as $country) {
            if ($currency = Market::currency($country['code'])) {
                $currencies[$currency] = true;
            }
        }

        return [
            'countries' => count($countries),
            'providers' => count(self::driverClasses()),
            'currencies' => count($currencies),
        ];
    }

    /**
     * The auto-discovered payment-provider driver classes.
     *
     * @return list<class-string<PaymentProviderInterface>>
     */
    private static function driverClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Http/Controllers/Providers/*.php')) ?: [] as $file) {
            $class = 'App\\Http\\Controllers\\Providers\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, PaymentProviderInterface::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
