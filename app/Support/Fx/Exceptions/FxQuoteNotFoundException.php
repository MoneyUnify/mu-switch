<?php

namespace App\Support\Fx\Exceptions;

class FxQuoteNotFoundException extends FxException
{
    public function __construct(string $quoteId)
    {
        parent::__construct("FX quote {$quoteId} was not found.");
    }
}
