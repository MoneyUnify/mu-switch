<?php

namespace App\Support;

use App\Models\ProviderLog;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persists the switch's outgoing calls to a payment gateway (request + response,
 * including auth/token calls) against the provider currently held in
 * {@see ProviderCallContext}. Driven by Laravel's HTTP-client events, so every
 * driver that uses the `Http` facade is logged with no per-driver wiring.
 *
 * Each call is written (and committed) the moment it is *sent*, then updated
 * with its outcome when the response arrives or the connection fails. So if a
 * call hangs and the worker is killed mid-flight, an "initiated" row survives —
 * the last committed trace of what the switch was doing.
 */
class ProviderCallLogger
{
    /**
     * In-flight calls: spl_object_id(request) => ['id' => provider_log id,
     * 'url' => target], so the outcome can update the same row the send created.
     * The response event reuses the request object (matched by id); the
     * connection-failed event wraps a fresh one (matched by url — calls are
     * sequential, so there is only ever one in-flight row per url).
     *
     * @var array<int, array{id: int, url: string}>
     */
    private static array $inFlight = [];

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
     * Record (and commit) a call the moment it is sent, before it blocks on the
     * network — so an interrupted call still leaves a traceable row.
     */
    public function recordRequest(RequestSending $event): void
    {
        if (! ProviderCallContext::active()) {
            return;
        }

        $log = $this->create($event->request);

        if ($log) {
            self::$inFlight[spl_object_id($event->request)] = ['id' => $log->id, 'url' => $event->request->url()];
        }
    }

    /**
     * Update the call with its outcome once the response arrives (any HTTP
     * status — 2xx, 4xx, 5xx).
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

        $this->complete($event->request, [
            'response_status' => $event->response->status(),
            'response_body' => $this->truncate($event->response->body()),
            'duration_ms' => $durationMs,
            'failed' => $event->response->failed(),
        ]);
    }

    /**
     * Update the call as failed when the connection never completed (e.g. the
     * gateway was unreachable).
     */
    public function recordConnectionFailure(ConnectionFailed $event): void
    {
        if (! ProviderCallContext::active()) {
            return;
        }

        $this->complete($event->request, [
            'failed' => true,
            'error_message' => 'Connection failed: the gateway could not be reached',
        ]);
    }

    /**
     * Create the initiated provider-log row. Logging failures must never break
     * a payment.
     */
    private function create(ClientRequest $request): ?ProviderLog
    {
        try {
            return ProviderLog::create([
                'payment_provider_id' => ProviderCallContext::providerId(),
                'user_id' => ProviderCallContext::userId(),
                'request_id' => RequestContext::id(),
                'method' => $request->method(),
                'url' => $request->url(),
                'host' => parse_url($request->url(), PHP_URL_HOST) ?: null,
                'request_headers' => $this->redactHeaders($request->headers()),
                'request_body' => $this->requestBody($request),
                'failed' => false,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to persist provider log', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Stamp the outcome onto the in-flight row, or create a complete row if the
     * send was never recorded (defensive — keeps every outcome traceable).
     *
     * @param  array<string, mixed>  $outcome
     */
    private function complete(ClientRequest $request, array $outcome): void
    {
        $logId = $this->pullInFlightId($request);

        try {
            $log = $logId ? ProviderLog::find($logId) : null;

            if ($log) {
                $log->update($outcome);

                return;
            }

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
     * Find and remove the in-flight row id for a completing call — by request
     * object (the response event) or, failing that, by url (the connection
     * failure event wraps a fresh request object).
     */
    private function pullInFlightId(ClientRequest $request): ?int
    {
        $key = spl_object_id($request);

        if (isset(self::$inFlight[$key])) {
            $id = self::$inFlight[$key]['id'];
            unset(self::$inFlight[$key]);

            return $id;
        }

        foreach (self::$inFlight as $objectId => $entry) {
            if ($entry['url'] === $request->url()) {
                unset(self::$inFlight[$objectId]);

                return $entry['id'];
            }
        }

        return null;
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
