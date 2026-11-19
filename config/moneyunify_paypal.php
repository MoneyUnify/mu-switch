<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Foreign Exchange (FX) Configuration
    |--------------------------------------------------------------------------
    |
    | Rates and quoting parameters for cross-currency transfers (e.g. PayPal
    | USD funding to African mobile-money payouts like ZMW, KES, UGX).
    |
    */
    'fx' => [
        // Driver for exchange rates: 'static' (default for sandbox)
        'driver' => env('FX_DRIVER', 'static'),

        // How long an FX quote remains locked (in minutes) before expiring.
        'quote_ttl_minutes' => (int) env('FX_QUOTE_TTL_MINUTES', 10),

        // Default platform margin / spread percentage applied to the base rate.
        // For example, a 1.5% margin on USD/ZMW at 27.50 gives 27.0875 ZMW per USD.
        'margin_percent' => (float) env('FX_MARGIN_PERCENT', 1.5),

        // Base currency used for the rate matrix.
        'base_currency' => 'USD',

        // Sandbox mid-market exchange rates against 1 USD.
        // Includes major funding currencies and key mobile-money corridor destinations.
        'rates' => [
            // Major funding currencies (PayPal supported)
            'USD' => 1.0,
            'EUR' => 0.92,
            'GBP' => 0.79,
            'CAD' => 1.38,
            'AUD' => 1.52,

            // African mobile money corridor destinations
            'ZMW' => 27.50,    // Zambia (Kwacha)
            'KES' => 130.00,   // Kenya (Shilling)
            'UGX' => 3700.00,  // Uganda (Shilling - zero decimal)
            'TZS' => 2600.00,  // Tanzania (Shilling)
            'GHS' => 15.50,    // Ghana (Cedi)
            'RWF' => 1350.00,  // Rwanda (Franc - zero decimal)
            'MWK' => 1750.00,  // Malawi (Kwacha)
            'XOF' => 600.00,   // West African CFA Franc (zero decimal)
            'XAF' => 600.00,   // Central African CFA Franc (zero decimal)
            'ZAR' => 18.20,    // South Africa (Rand)
            'NGN' => 1600.00,  // Nigeria (Naira)
            'CDF' => 2850.00,  // DR Congo (Franc)
            'MZN' => 63.90,    // Mozambique (Metical)
        ],

        // Explicit custom pair overrides if needed (format: 'FROM_TO' => rate)
        'custom_pairs' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Spending Caps & Guardrails
    |--------------------------------------------------------------------------
    |
    | Limits on transfer amounts enforced in code to protect against runaway
    | agent transfers or excessive unauthorized sends.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Impact
    |--------------------------------------------------------------------------
    |
    | The average all-in cost of sending money to your corridors today (e.g.
    | the World Bank Remittance Prices Worldwide figure for your region), in
    | percent. When set, the Merchants page compares your merchants' cost with
    | it. Leave unset to show your own cost only.
    |
    */
    'impact' => [
        'benchmark_cost_percent' => env('IMPACT_BENCHMARK_COST_PERCENT'),
    ],

    'limits' => [
        'per_transaction_max_usd' => (float) env('TRANSFER_MAX_PER_TRANSACTION_USD', 2000.00),
        'daily_max_usd' => (float) env('TRANSFER_MAX_DAILY_USD', 5000.00),
    ],

];
