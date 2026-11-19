<?php

namespace App\Support\Fx\Exceptions;

class FxQuoteExpiredException extends FxException
{
    public function __construct(string $quoteId)
    {
        parent::__construct("FX quote {$quoteId} has expired. Please request a new quote.");
    }
}
