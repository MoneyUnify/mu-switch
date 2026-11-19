<?php

namespace App\Support\Paypal;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Raised when a PayPal API call fails, carrying PayPal's HTTP status and
 * error `issue` code (e.g. ORDER_ALREADY_CAPTURED).
 */
class PaypalException extends RuntimeException
{
    /**
     * @param  ?string  $actionUrl  where PayPal says the account can fix it (e.g. activate a feature)
     */
    public function __construct(string $message, public readonly int $status = 0, public readonly ?string $issue = null, public readonly ?string $actionUrl = null)
    {
        parent::__construct($message);
    }

    /**
     * Build from a failed PayPal response, using its most specific error text.
     */
    public static function fromResponse(Response $response): self
    {
        return new self(
            $response->json('details.0.description')
                ?? $response->json('message')
                ?? $response->json('error_description')
                ?? 'PayPal request failed',
            $response->status(),
            $response->json('details.0.issue') ?? $response->json('name') ?? $response->json('error'),
            $response->json('details.0.links.0.href'),
        );
    }

    /**
     * PayPal could not be reached at all.
     */
    public static function unreachable(): self
    {
        return new self('PayPal could not be reached', 503);
    }

    /**
     * The resource (e.g. an expired order) no longer exists.
     */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /**
     * The account isn't set up for the feature (e.g. FX as a Service).
     */
    public function isNotProvisioned(): bool
    {
        return in_array($this->issue, ['FEATURE_NOT_PROVISIONED', 'NOT_AUTHORIZED', 'PERMISSION_DENIED'], true) || $this->status === 403;
    }

    /**
     * PayPal understood the request but refused it (e.g. a declined capture).
     */
    public function isUnprocessable(): bool
    {
        return $this->status === 422;
    }
}
