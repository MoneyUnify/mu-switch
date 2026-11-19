<?php

namespace App\Support\Paypal;

use App\Support\Market;
use Locale;

/**
 * The countries and regions where PayPal supports account payments for
 * business accounts, so where a payer can pay a MoneyUnify merchant or
 * sender from. China is listed by PayPal as C2.
 *
 * @see https://developer.paypal.com/api/rest/reference/country-codes/
 */
final class PaypalMarkets
{
    /** @var list<string> PayPal's codes, as PayPal lists them (checked 2026-10-18). */
    public const CODES = [
        'AL', 'DZ', 'AD', 'AO', 'AI', 'AG', 'AR', 'AM', 'AW', 'AU', 'AT', 'AZ', 'BS', 'BH', 'BB', 'BY', 'BE', 'BZ', 'BJ', 'BM',
        'BT', 'BO', 'BA', 'BW', 'BR', 'VG', 'BN', 'BG', 'BF', 'BI', 'KH', 'CM', 'CA', 'CV', 'KY', 'TD', 'CL', 'C2', 'CO', 'KM',
        'CG', 'CD', 'CK', 'CR', 'CI', 'HR', 'CY', 'CZ', 'DK', 'DJ', 'DM', 'DO', 'EC', 'EG', 'SV', 'ER', 'EE', 'ET', 'FK', 'FO',
        'FJ', 'FI', 'FR', 'GF', 'PF', 'GA', 'GM', 'GE', 'DE', 'GI', 'GR', 'GL', 'GD', 'GP', 'GT', 'GN', 'GW', 'GY', 'HN', 'HK',
        'HU', 'IS', 'IN', 'ID', 'IE', 'IL', 'IT', 'JM', 'JP', 'JO', 'KZ', 'KE', 'KI', 'KW', 'KG', 'LA', 'LV', 'LS', 'LI', 'LT',
        'LU', 'MK', 'MG', 'MW', 'MY', 'MV', 'ML', 'MT', 'MH', 'MQ', 'MR', 'MU', 'YT', 'MX', 'FM', 'MD', 'MC', 'MN', 'ME', 'MS',
        'MA', 'MZ', 'NA', 'NR', 'NP', 'NL', 'NC', 'NZ', 'NI', 'NE', 'NG', 'NU', 'NF', 'NO', 'OM', 'PW', 'PA', 'PG', 'PY', 'PE',
        'PH', 'PN', 'PL', 'PT', 'QA', 'RE', 'RO', 'RU', 'RW', 'WS', 'SM', 'ST', 'SA', 'SN', 'RS', 'SC', 'SL', 'SG', 'SK', 'SI',
        'SB', 'SO', 'ZA', 'KR', 'ES', 'LK', 'SH', 'KN', 'LC', 'PM', 'VC', 'SR', 'SJ', 'SZ', 'SE', 'CH', 'TW', 'TJ', 'TZ', 'TH',
        'TG', 'TO', 'TT', 'TN', 'TM', 'TC', 'TV', 'UG', 'UA', 'AE', 'GB', 'US', 'UY', 'VU', 'VA', 'VE', 'VN', 'WF', 'YE', 'ZM',
        'ZW',
    ];

    /**
     * PayPal's markets as ISO 3166 codes (PayPal's C2 is China, CN).
     *
     * @return list<string>
     */
    public static function isoCodes(): array
    {
        return array_values(array_unique(array_map(fn (string $code): string => $code === 'C2' ? 'CN' : $code, self::CODES)));
    }

    /**
     * A country's English name.
     */
    public static function name(string $code): string
    {
        if (Market::isKnown($code)) {
            return Market::name($code);
        }

        $name = class_exists(Locale::class) ? Locale::getDisplayRegion('-'.$code, 'en') : '';

        return $name !== '' && $name !== $code ? $name : $code;
    }
}
