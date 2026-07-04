<?php

namespace App\Http\Controllers\Providers;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Support\Market;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Ting(g) by Cellulant driver — the Tingg Checkout API (v3), direct STK charge.
 *
 * Tingg authenticates with OAuth client-credentials (Client ID + Secret →
 * access token) plus an API key header, then the combined `checkout-charge`
 * endpoint (with `is_offline: true`) pushes an STK/USSD prompt straight to the
 * payer's handset — no hosted payment link. Status is confirmed with the query
 * endpoint.
 *
 * The prompt is routed to a specific mobile-money operator by its Tingg
 * **payment option code** (e.g. `SAFKE` for Safaricom Kenya, `VODACOMTZ` for
 * Vodacom Tanzania). These codes are assigned by Tingg per operator — there is
 * no derivable global standard — so each provider is configured with the code
 * for the operator it serves (add one Ting provider per operator/market).
 *
 * @see https://dev-portal.tingg.africa/api-docs
 */
class TingController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'Ting by Cellulant';

    /**
     * Tingg's pan-African footprint (selectable in the dashboard). Enable only
     * the markets your Tingg service is contracted for.
     */
    public const SUPPORTED_COUNTRIES = [
        'KE', 'UG', 'TZ', 'RW', 'ZM', 'MW', 'GH', 'NG', 'CI', 'CM',
        'GA', 'CG', 'CD', 'TD', 'NE', 'BJ', 'GN', 'GW', 'LR', 'MZ',
        'ZA', 'SZ', 'LS', 'SS', 'SC',
    ];

    public const DEFAULT_COUNTRIES = 'KE';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/ting.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
        ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
        ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
        ['key' => 'service_code', 'label' => 'Service Code', 'type' => 'text'],
    ];

    /**
     * Ting routes the STK prompt to a specific mobile-money operator by a code
     * Tingg assigns per operator. Since one provider can serve many markets, the
     * dashboard collects a code for EACH ticked market; the switch then picks the
     * code for the request's country. Declaring MARKET_FIELD makes the provider
     * dialog render a per-market input.
     */
    public const MARKET_FIELD = [
        'key' => 'payment_option_codes',
        'label' => 'Payment Option Code',
        'placeholder' => 'e.g. SAFKE',
    ];

    /**
     * Tingg production host (we run providers in production only).
     */
    private const BASE_URL = 'https://api.tingg.africa';

    /**
     * Tingg checkout request status codes (returned by the query endpoint).
     * 178 = fully paid; 99 = failed; 129 = expired; everything else (partial,
     * awaiting payment) is still in progress.
     */
    private const STATUS_PAID = 178;

    private const STATUS_FAILED = 99;

    private const STATUS_EXPIRED = 129;

    private string $apiKey;

    private string $clientId;

    private string $clientSecret;

    private string $serviceCode;

    /**
     * Map of country code => Tingg operator payment option code.
     *
     * @var array<string, string>
     */
    private array $paymentOptionCodes = [];

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $apiKey = $config['api_key'] ?? null;
        $clientId = $config['client_id'] ?? null;
        $clientSecret = $config['client_secret'] ?? null;
        $serviceCode = $config['service_code'] ?? null;

        if (! $apiKey || ! $clientId || ! $clientSecret || ! $serviceCode) {
            return ApiResponse::error('API Key, Client ID, Client Secret and Service Code are required for the Ting provider', 400);
        }

        $this->apiKey = $apiKey;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->serviceCode = (string) $serviceCode;
        $this->paymentOptionCodes = is_array($config['payment_option_codes'] ?? null) ? $config['payment_option_codes'] : [];
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate a collection with Tingg and return the hosted payment link.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

        // The operator the STK prompt is routed to, chosen by the request country.
        $paymentOptionCode = trim((string) ($this->paymentOptionCodes[$country] ?? ''));
        if ($paymentOptionCode === '') {
            return ApiResponse::error("No Ting payment option code is configured for {$country}", 422);
        }

        $token = $this->getAccessToken();
        if (! $token) {
            return ApiResponse::error('Could not authenticate with Ting', 502);
        }

        $customer = $this->resolveCustomer($msisdn, $country);

        // Tingg looks a request up by (service_code, merchant_transaction_id),
        // which is our own transaction_id.
        $reference = $this->newReference();

        $transaction = new Transaction([
            'transaction_id' => $reference,
            'customer_id' => $customer->id,
            'amount' => $request['amount'],
            'currency' => $currency,
            'country' => $country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'credit',
            'provider_transaction_id' => $reference,
            'callback_url' => $request->input('callback_url'),
        ]);
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        $email = trim((string) $request->input('email'));

        // Combined checkout + charge with is_offline pushes the STK/USSD prompt
        // directly to the payer (no hosted link); payment_option_code routes it
        // to the configured mobile-money operator.
        $payload = array_filter([
            'msisdn' => $msisdn,
            'account_number' => $msisdn,
            'callback_url' => $this->callbackUrl($request),
            'country_code' => Market::alpha3($country),
            'currency_code' => $currency,
            'customer_email' => $email !== '' ? $email : null,
            // Names are mandatory for Tingg; default to an unnamed customer when
            // the caller does not supply them.
            'customer_first_name' => trim((string) $request->input('first_name')) ?: 'Unnamed',
            'customer_last_name' => trim((string) $request->input('last_name')) ?: 'Customer',
            'merchant_transaction_id' => $reference,
            'request_amount' => (float) $request['amount'],
            'request_description' => 'Payment collection',
            'service_code' => $this->serviceCode,
            'success_redirect_url' => $this->redirectUrl(),
            'fail_redirect_url' => $this->redirectUrl(),
            'is_offline' => true,
            'payment_option_code' => $paymentOptionCode,
        ], fn ($value) => $value !== null);

        $response = Http::withHeaders($this->authHeaders($token))
            ->post(self::BASE_URL.'/v3/checkout-api/checkout-charge', $payload);

        if (! $this->accepted($response)) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json() ?: ['body' => $response->body()],
            ]);

            return ApiResponse::error($this->statusMessageFrom($response) ?? 'Ting payment request failed', $this->errorCode($response));
        }

        $transaction->update([
            'provider_transaction_id' => (string) ($this->pick($response, 'checkout_request_id') ?? $reference),
            'status' => TransactionStatus::PENDING,
            'provider_response' => $response->json(),
        ]);

        return ApiResponse::success('Payment request initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
        ]);
    }

    /**
     * Re-check a transaction with Tingg and persist its latest status.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return ApiResponse::error('Could not authenticate with Ting', 502);
        }

        $response = Http::withHeaders($this->authHeaders($token))
            ->get(self::BASE_URL.'/v3/checkout-api/query/'.$this->serviceCode.'/'.$transaction->transaction_id);

        if (! $response->successful()) {
            return ApiResponse::error($this->statusMessageFrom($response) ?? 'Unable to verify the transaction with Ting', $response->status() >= 400 ? $response->status() : 502);
        }

        $statusCode = $this->pick($response, 'request_status_code');
        $status = self::mapStatus($statusCode !== null ? (int) $statusCode : null);

        $transaction->update([
            'status' => $status,
            'provider_response' => $response->json(),
        ]);

        return ApiResponse::status($status->value, self::statusMessage($status), [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $status->value,
            'provider_status' => (string) ($statusCode ?? ''),
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
        ]);
    }

    /**
     * Exchange the Client ID / Secret for a bearer access token, cached until
     * shortly before it expires.
     */
    private function getAccessToken(): ?string
    {
        $cacheKey = 'ting_access_token_'.sha1($this->clientId.'|'.$this->apiKey);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $response = Http::withHeaders(['apiKey' => $this->apiKey])
            ->acceptJson()
            ->asJson()
            ->post(self::BASE_URL.'/v1/oauth/token/request', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! $token) {
            return null;
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        Cache::put($cacheKey, $token, now()->addSeconds(max(60, $expiresIn - 60)));

        return $token;
    }

    /**
     * The headers every authenticated Tingg call requires.
     *
     * @return array<string, string>
     */
    private function authHeaders(string $token): array
    {
        return [
            'apiKey' => $this->apiKey,
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Whether a checkout response reports success (status_code 200).
     */
    private function accepted(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $code = $response->json('status.status_code') ?? $response->json('status_code');

        return (int) $code === 200 || $this->pick($response, 'checkout_request_id') !== null;
    }

    /**
     * Read a value from a Tingg response by key, whether it sits at the top
     * level or nested under `results` / `status`.
     */
    private function pick(Response $response, string $key): mixed
    {
        return $response->json('results.'.$key)
            ?? $response->json($key)
            ?? $response->json('status.'.$key);
    }

    /**
     * A human-readable status/error message from a Tingg response.
     */
    private function statusMessageFrom(Response $response): ?string
    {
        return $response->json('status.status_description')
            ?? $response->json('message')
            ?? $response->json('error');
    }

    /**
     * The HTTP status to surface for a rejected request (always a 4xx/5xx).
     */
    private function errorCode(Response $response): int
    {
        return $response->status() >= 400 ? $response->status() : 422;
    }

    /**
     * The callback URL Tingg posts the result to.
     */
    private function callbackUrl(Request $request): string
    {
        return $request->input('callback_url') ?: rtrim((string) config('app.url'), '/').'/api/v1/payment/ting/callback';
    }

    /**
     * The redirect URL for the hosted checkout page (server-to-server flows do
     * not use it, but Tingg requires a valid URL).
     */
    private function redirectUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * A unique merchant transaction reference (<= 100 chars, no special chars).
     */
    private function newReference(): string
    {
        return 'MU'.now()->format('YmdHis').Str::upper(Str::random(8));
    }

    /**
     * Find or create the payer's customer record idempotently.
     */
    private function resolveCustomer(string $msisdn, string $country): Customer
    {
        return DB::transaction(function () use ($msisdn, $country): Customer {
            $customer = Customer::firstOrCreate(
                ['email' => $msisdn.'@moneyunify.local'],
                ['name' => 'Customer '.$msisdn],
            );

            CustomerAccount::updateOrCreate(
                ['number' => $msisdn, 'country' => $country],
                ['customer_id' => $customer->id, 'name' => $customer->name],
            );

            return $customer;
        });
    }

    /**
     * Normalise a phone number to the E.164 MSISDN Tingg expects (country code,
     * no leading zero, no '+', e.g. "254700123456").
     */
    private function normaliseMsisdn(string $number, string $country): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $digits = ltrim($digits, '0');

        $callingCode = Market::callingCode($country);

        if (! $callingCode || str_starts_with($digits, $callingCode)) {
            return $digits;
        }

        return $callingCode.$digits;
    }

    /**
     * Map a Tingg checkout status code onto our internal TransactionStatus.
     */
    private static function mapStatus(?int $statusCode): TransactionStatus
    {
        return match ($statusCode) {
            self::STATUS_PAID => TransactionStatus::SUCCESS,
            self::STATUS_FAILED, self::STATUS_EXPIRED => TransactionStatus::FAILED,
            default => TransactionStatus::PENDING,
        };
    }

    /**
     * A human-readable message that matches the transaction outcome.
     */
    private static function statusMessage(TransactionStatus $status): string
    {
        return match ($status) {
            TransactionStatus::SUCCESS => 'Transaction completed successfully',
            TransactionStatus::FAILED => 'Transaction failed',
            default => 'Transaction is still pending',
        };
    }
}
