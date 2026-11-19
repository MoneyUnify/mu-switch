<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A refund that isn't allowed; the message says why.
 */
class RefundException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
