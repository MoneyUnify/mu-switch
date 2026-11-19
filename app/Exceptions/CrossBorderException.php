<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A cross-border transfer that can't be quoted or started; the message says why.
 */
class CrossBorderException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
