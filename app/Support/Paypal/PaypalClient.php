<?php

namespace App\Support\Paypal;

use App\Enums\PaypalEnvironment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin client for the PayPal REST API: OAuth, Orders v2 and webhook
 * verification. Every call throws {@see PaypalException} on failure.
 *
 * Every call is safe to repeat (reads, or writes carrying a PayPal-Request-Id),
 * so a stalled or 5xx call is retried within the gateway wait budget instead
 * of failing on PayPal's first hiccup.
 *
 * @see https://developer.paypal.com/docs/api/orders/v2/
 */
class PaypalClient
{
    /**
     * Pause before each retry, in milliseconds: one entry per retry, so three
     * tries in all.
     *
     * @var list<int>
     */
    private const RETRY_DELAYS = [500, 1500];

    /** Seconds a quick call (see quick()) may wait for PayPal's answer. */
    private const QUICK_TIMEOUT = 10;

    /** Calls that fail fast instead of retrying (see quick()). */
    private bool $quick = false;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly PaypalEnvironment $environment,
    ) {}

    /**
     * Build a client from a provider's config, or null when it is incomplete.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): ?self
    {
        $clientId = trim((string) ($config['client_id'] ?? ''));
        $clientSecret = trim((string) ($config['client_secret'] ?? ''));
        $environment = PaypalEnvironment::tryFrom((string) ($config['environment'] ?? PaypalEnvironment::SANDBOX->value));

        if ($clientId === '' || $clientSecret === '' || ! $environment) {
            return null;
        }

        return new self($clientId, $clientSecret, $environment);
    }

    /**
     * A copy that tries once with a short answer timeout, for calls with a
     * safe fallback (such as exchange rates), so a slow PayPal never holds up
     * the caller. The connect allowance is kept: some networks take seconds
     * to reach PayPal.
     */
    public function quick(): static
    {
        $client = clone $this;
        $client->quick = true;

        return $client;
    }

    public function environment(): PaypalEnvironment
    {
        return $this->environment;
    }

    /**
     * Create a checkout order.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createOrder(array $payload, string $requestId): array
    {
        return $this->send('POST', '/v2/checkout/orders', $payload, $requestId);
    }

    /**
     * Fetch an order's current state.
     *
     * @return array<string, mixed>
     */
    public function showOrder(string $orderId): array
    {
        return $this->send('GET', '/v2/checkout/orders/'.rawurlencode($orderId));
    }

    /**
     * Capture an approved order. The request id makes retries safe.
     *
     * @return array<string, mixed>
     */
    public function captureOrder(string $orderId, string $requestId): array
    {
        return $this->send('POST', '/v2/checkout/orders/'.rawurlencode($orderId).'/capture', (object) [], $requestId);
    }

    /**
     * Refund a captured payment, in full or in part. The request id makes
     * retries safe: PayPal returns the same refund instead of a second one.
     *
     * @return array<string, mixed>
     */
    public function refundCapture(string $captureId, string $amount, string $currency, string $requestId, ?string $note = null, ?string $customId = null): array
    {
        return $this->send('POST', '/v2/payments/captures/'.rawurlencode($captureId).'/refund', array_filter([
            'amount' => ['value' => $amount, 'currency_code' => strtoupper($currency)],
            'note_to_payer' => $note === null ? null : mb_substr($note, 0, 255),
            'custom_id' => $customId,
        ]), $requestId);
    }

    /**
     * Fetch a refund's current state.
     *
     * @return array<string, mixed>
     */
    public function showRefund(string $refundId): array
    {
        return $this->send('GET', '/v2/payments/refunds/'.rawurlencode($refundId));
    }

    /**
     * PayPal's exchange rates (FX as a Service) from a currency the account
     * holds into each quote currency, with the `fx_id` that locks a rate to
     * an order.
     *
     * @param  list<string>  $quoteCurrencies
     * @return list<array<string, mixed>> PayPal's `exchange_rate_quotes`
     *
     * @see https://developer.paypal.com/docs/checkout/fx-as-a-service/integrate/
     */
    public function quoteExchangeRates(string $baseCurrency, array $quoteCurrencies): array
    {
        $response = $this->send('POST', '/v2/pricing/quote-exchange-rates', [
            'quote_items' => array_map(fn (string $currency): array => [
                'base_currency' => strtoupper($baseCurrency),
                'quote_currency' => strtoupper($currency),
            ], array_values($quoteCurrencies)),
        ]);

        return array_values((array) ($response['exchange_rate_quotes'] ?? []));
    }

    /**
     * Ask PayPal whether a webhook delivery is genuine.
     *
     * The raw event body is forwarded untouched, since re-encoding it can
     * break the signature check.
     *
     * @param  array{auth_algo: ?string, cert_url: ?string, transmission_id: ?string, transmission_sig: ?string, transmission_time: ?string}  $transmission
     */
    public function verifyWebhookSignature(array $transmission, string $rawEvent, string $webhookId): bool
    {
        if (in_array(null, $transmission, true) || ! json_validate($rawEvent)) {
            return false;
        }

        $envelope = json_encode([...$transmission, 'webhook_id' => $webhookId], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $body = substr($envelope, 0, -1).',"webhook_event":'.$rawEvent.'}';

        $response = $this->call(fn (): Response => $this->authorised()
            ->withBody($body, 'application/json')
            ->post($this->url('/v1/notifications/verify-webhook-signature')));

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    /**
     * Send an authorised JSON request, refreshing the token once on a 401.
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array|object|null $body = null, ?string $requestId = null): array
    {
        $options = $body === null ? [] : ['json' => $body];
        $request = fn (): Response => $this->authorised($requestId)->send($method, $this->url($path), $options);

        $response = $this->call($request);

        if ($response->status() === 401) {
            Cache::forget($this->tokenCacheKey());
            $response = $this->call($request);
        }

        if ($response->failed()) {
            throw PaypalException::fromResponse($response);
        }

        return $response->json() ?? [];
    }

    /**
     * Seconds each try may wait for an answer, so all tries fit the gateway timeout.
     */
    public static function attemptTimeout(): int
    {
        $attempts = count(self::RETRY_DELAYS) + 1;

        return max(10, intdiv((int) config('services.gateways.timeout'), $attempts));
    }

    /**
     * A JSON request that retries stalls and PayPal server errors.
     */
    private function request(): PendingRequest
    {
        $request = Http::acceptJson()
            ->connectTimeout(max((int) config('services.gateways.connect_timeout'), (int) config('services.paypal.connect_timeout', 20)));

        if ($this->quick) {
            return $request->timeout(self::QUICK_TIMEOUT);
        }

        return $request
            ->timeout(self::attemptTimeout())
            ->retry(
                [...self::RETRY_DELAYS],
                when: fn (Throwable $e): bool => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            );
    }

    /**
     * A request carrying the bearer token (and an idempotency key, if given).
     */
    private function authorised(?string $requestId = null): PendingRequest
    {
        $request = $this->request()->withToken($this->accessToken());

        return $requestId ? $request->withHeaders(['PayPal-Request-Id' => $requestId]) : $request;
    }

    /**
     * An OAuth access token, cached until shortly before it expires.
     */
    public function accessToken(): string
    {
        if ($cached = Cache::get($this->tokenCacheKey())) {
            return $cached;
        }

        $response = $this->call(fn (): Response => $this->request()
            ->asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->post($this->url('/v1/oauth2/token'), ['grant_type' => 'client_credentials']));

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            throw PaypalException::fromResponse($response);
        }

        $ttl = max(60, (int) $response->json('expires_in', 0) - 120);
        Cache::put($this->tokenCacheKey(), $token, now()->addSeconds($ttl));

        return $token;
    }

    /**
     * Run a request, translating connection failures into a PaypalException.
     *
     * @param  callable(): Response  $request
     */
    private function call(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            // Keep the underlying cause (timeout, reset, DNS); the caller only sees "unreachable".
            Log::warning('PayPal could not be reached', [
                'environment' => $this->environment->value,
                'error' => $e->getMessage(),
            ]);

            throw PaypalException::unreachable();
        }
    }

    private function url(string $path): string
    {
        return $this->environment->apiUrl().$path;
    }

    /**
     * Unique per environment and credentials, so a rotated secret gets a fresh token.
     */
    private function tokenCacheKey(): string
    {
        return 'paypal_access_token_'.sha1($this->environment->value.'|'.$this->clientId.'|'.$this->clientSecret);
    }
}
