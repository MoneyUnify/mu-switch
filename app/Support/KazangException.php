<?php

namespace App\Support;

use RuntimeException;

/**
 * Raised when a Kazang ContentReady API call returns a non-success response,
 * carrying the decoded payload for logging.
 */
class KazangException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(string $message, public array $payload = [])
    {
        parent::__construct($message);
    }
}
