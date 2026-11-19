<?php

namespace App\Support\Fx\Exceptions;

class UnsupportedCurrencyPairException extends FxException
{
    public function __construct(string $fromCurrency, string $toCurrency)
    {
        parent::__construct("No exchange rate available between {$fromCurrency} and {$toCurrency}.");
    }
}
