<?php

namespace App\Support;

use App\Support\Paypal\PaypalMarkets;

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
     * Every country the switch reaches: where payers can pay in from with
     * PayPal, and where money can be collected and paid out on mobile money,
     * each marked with which of the two it has, sorted by name.
     *
     * @return list<array{code: string, name: string, paypal: bool, mobile_money: bool}>
     */
    public static function reach(): array
    {
        $mobileMoney = array_column(self::countries(), 'name', 'code');
        $paypal = array_flip(PaypalMarkets::isoCodes());
        $reach = [];

        foreach (array_keys($mobileMoney + $paypal) as $code) {
            $reach[] = [
                'code' => (string) $code,
                'name' => $mobileMoney[$code] ?? PaypalMarkets::name((string) $code),
                'paypal' => isset($paypal[$code]),
                'mobile_money' => isset($mobileMoney[$code]),
            ];
        }

        usort($reach, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $reach;
    }

    /**
     * Headline coverage figures for value-proposition surfaces. `countries`
     * is where mobile money is collected and paid out; `paypal_countries`
     * where payers can pay in from with PayPal; `reach` both together.
     *
     * @return array{countries: int, paypal_countries: int, reach: int, providers: int, currencies: int}
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
            'paypal_countries' => count(PaypalMarkets::isoCodes()),
            'reach' => count(self::reach()),
            'providers' => count(self::driverClasses()),
            'currencies' => count($currencies),
        ];
    }

    /**
     * The real (non-sandbox) driver classes.
     *
     * @return list<class-string>
     */
    private static function driverClasses(): array
    {
        return DriverRegistry::classes(includeSandboxOnly: false);
    }
}
