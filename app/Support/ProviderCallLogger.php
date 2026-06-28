<?php

namespace App\Support;

use App\Models\ProviderLog;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists the switch's outgoing calls to a payment gateway (request + response,
 * including auth/token calls) against the provider currently held in
 * {@see ProviderCallContext}. Driven by Laravel's HTTP-client events, so every
 * driver that uses the `Http` facade is logged with no per-driver wiring.
 */
class ProviderCallLogger
{
    /**
     * Header names whose values must never be persisted.
     *
     * @var list<string>
     */
    private const REDACTED_HEADERS = ['authorization', 'ocp-apim-subscription-key', 'x-api-key', 'cookie', 'set-cookie'];

    /**
     * Request body keys whose values must never be persisted.
     *
     * @var list<string>
     */
    private const REDACTED_FIELDS = ['client_secret', 'client_id', 'api_key', 'apikey', 'secret', 'password', 'token', 'access_token', 'subscription_key'];

    /**
     * Maximum number of characters of a body to persist.
     */
    private const MAX_BODY_LENGTH = 20000;

    /**
     * Record a completed call (any HTTP status — 2xx, 4xx, 5xx).
     */
    public function recordResponse(ResponseReceived $event): void
    {
        if (! ProviderCallContext::active()) {
            return;
        }

        $durationMs = null;
        $transferTime = $event->response->transferStats?->getTransferTime();
        if ($transferTime !== null) {
            $durationMs = (int) round($transferTime * 1000);
        }

        $this->persist($event->request, [
            'response_status' => $event->response->status(),
            'response_body' => $this->truncate($event->response->body()),
            'duration_ms' => $durationMs,
            'failed' => $event->response->failed(),
        ]);
    }

    /**
     * Record a call that never completed (e.g. the gateway was unreachable).
     */
    public function recordConnectionFailure(ConnectionFailed $event): void
    {
        if (! ProviderCallContext::active()) {
            return;
        }

        $this->persist($event->request, [
            'failed' => true,
            'error_message' => 'Connection failed: the gateway could not be reached',
        ]);
    }

    /**
     * Persist a provider-log row. Logging failures must never break a payment.
     *
     * @param  array<string, mixed>  $outcome
     */
    private function persist(ClientRequest $request, array $outcome): void
    {
        try {
            ProviderLog::create([
                'payment_provider_id' => ProviderCallContext::providerId(),
                'user_id' => ProviderCallContext::userId(),
                'request_id' => RequestContext::id(),
                'method' => $request->method(),
                'url' => $request->url(),
                'host' => parse_url($request->url(), PHP_URL_HOST) ?: null,
                'request_headers' => $this->redactHeaders($request->headers()),
                'request_body' => $this->requestBody($request),
                ...$outcome,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to persist provider log', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A redacted, truncated representation of the request body.
     */
    private function requestBody(ClientRequest $request): ?string
    {
        $decoded = json_decode($request->body(), true);

        if (is_array($decoded)) {
            return $this->truncate((string) json_encode($this->redactBody($decoded)));
        }

        // Non-JSON (e.g. form or empty) body — store as-is, truncated.
        $body = $request->body();

        return $body === '' ? null : $this->truncate($body);
    }

    /**
     * Redact sensitive header values.
     *
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, array<int, string>>
     */
    private function redactHeaders(array $headers): array
    {
        foreach ($headers as $name => $values) {
            if (in_array(strtolower((string) $name), self::REDACTED_HEADERS, true)) {
                $headers[$name] = ['[REDACTED]'];
            }
        }

        return $headers;
    }

    /**
     * Redact sensitive request body fields (recursively).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function redactBody(array $body): array
    {
        foreach ($body as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED_FIELDS, true)) {
                $body[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $body[$key] = $this->redactBody($value);
            }
        }

        return $body;
    }

    private function truncate(string $value): string
    {
        return mb_substr($value, 0, self::MAX_BODY_LENGTH);
    }
}
