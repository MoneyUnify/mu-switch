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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * M-Pesa Vodafone/Vodacom driver — the multi-country M-Pesa Open API (G2).
 *
 * Outside Kenya, M-Pesa runs on Vodafone/Vodacom's Open API platform
 * (openapi.m-pesa.com). Authentication is a two-step RSA handshake: the
 * Application API Key is encrypted with the platform's public key to fetch a
 * Session ID, and that Session ID — re-encrypted with the same public key —
 * becomes the bearer for the C2B "single stage" push and status calls. Each
 * market (Tanzania, Ghana, Mozambique, DRC, Lesotho, …) has its own path
 * segment, country code and currency, derived here from the request country.
 *
 * @see https://openapi.m-pesa.com/
 */
class MpesaVodacomController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'M-Pesa (Vodafone/Vodacom)';

    /**
     * Per-country Open API market: [market path segment, ISO-3 country code,
     * currency]. This is the single place to add or correct a market.
     *
     * @var array<string, array{market: string, country: string, currency: string}>
     */
    private const MARKETS = [
        'TZ' => ['market' => 'vodacomTZN', 'country' => 'TZN', 'currency' => 'TZS'],
        'GH' => ['market' => 'vodafoneGHA', 'country' => 'GHA', 'currency' => 'GHS'],
        'MZ' => ['market' => 'vodafoneMOZ', 'country' => 'MOZ', 'currency' => 'MZN'],
        'LS' => ['market' => 'vodacomLES', 'country' => 'LES', 'currency' => 'LSL'],
        'CD' => ['market' => 'vodacomDRC', 'country' => 'DRC', 'currency' => 'USD'],
    ];

    /**
     * Markets this driver can serve (selectable in the dashboard).
     */
    public const SUPPORTED_COUNTRIES = ['TZ', 'GH', 'MZ', 'LS', 'CD'];

    public const DEFAULT_COUNTRIES = 'TZ';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/mpesa_vodacom.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
        ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'password'],
        ['key' => 'service_provider_code', 'label' => 'Service Provider Code (Shortcode)', 'type' => 'text'],
    ];

    /**
     * M-Pesa Open API production host (we run providers in production only).
     */
    private const BASE_URL = 'https://openapi.m-pesa.com';

    private string $apiKey;

    private string $publicKey;

    private string $serviceProviderCode;

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $apiKey = $config['api_key'] ?? null;
        $publicKey = $config['public_key'] ?? null;
        $serviceProviderCode = $config['service_provider_code'] ?? null;

        if (! $apiKey || ! $publicKey || ! $serviceProviderCode) {
            return ApiResponse::error('API Key, Public Key and Service Provider Code are required for the M-Pesa (Vodafone/Vodacom) provider', 400);
        }

        $this->apiKey = $apiKey;
        $this->publicKey = $publicKey;
        $this->serviceProviderCode = (string) $serviceProviderCode;
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate a C2B "single stage" push (customer enters PIN on their handset).
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $market = self::MARKETS[$country] ?? null;
        if (! $market) {
            return ApiResponse::error("M-Pesa (Vodafone/Vodacom) does not support payments in {$country}", 422);
        }

        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

        $sessionBearer = $this->getSessionBearer($market['market']);
        if (! $sessionBearer) {
            return ApiResponse::error('Could not authenticate with M-Pesa', 502);
        }

        $customer = $this->resolveCustomer($msisdn, $country);

        $reference = $this->newReference();
        $conversationId = $this->newReference();

        $transaction = new Transaction([
            'transaction_id' => $reference,
            'customer_id' => $customer->id,
            'amount' => $request['amount'],
            'currency' => $market['currency'],
            'country' => $country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'credit',
            'provider_transaction_id' => $conversationId,
            'callback_url' => $request->input('callback_url'),
        ]);
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        $response = Http::withHeaders($this->headers($sessionBearer))
            ->post(self::BASE_URL."/openapi/ipg/v2/{$market['market']}/c2bPayment/singleStage/", [
                'input_Amount' => (string) $request['amount'],
                'input_Country' => $market['country'],
                'input_Currency' => $market['currency'],
                'input_CustomerMSISDN' => $msisdn,
                'input_ServiceProviderCode' => $this->serviceProviderCode,
                'input_ThirdPartyConversationID' => $conversationId,
                'input_TransactionReference' => $reference,
                'input_PurchasedItemsDesc' => 'Payment collection',
            ]);

        // The Open API accepts a request with output_ResponseCode "INS-0".
        $accepted = $response->successful() && (string) $response->json('output_ResponseCode') === 'INS-0';

        if (! $accepted) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json() ?: ['body' => $response->body()],
            ]);
            $message = $response->json('output_ResponseDesc') ?? 'M-Pesa payment request failed';
            $statusCode = $response->status() >= 400 ? $response->status() : 422;

            return ApiResponse::error($message, $statusCode);
        }

        $transaction->update([
            // Verification looks the transaction up by the M-Pesa transaction id.
            'provider_transaction_id' => $response->json('output_TransactionID') ?? $conversationId,
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
     * Re-check a transaction with the Open API and persist its latest status.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $country = (string) ($transaction->country ?: Market::countryForCurrency($transaction->currency));
        $market = self::MARKETS[strtoupper($country)] ?? null;
        if (! $market) {
            return ApiResponse::error('M-Pesa (Vodafone/Vodacom) does not support this transaction\'s country', 422);
        }

        $sessionBearer = $this->getSessionBearer($market['market']);
        if (! $sessionBearer) {
            return ApiResponse::error('Could not authenticate with M-Pesa', 502);
        }

        $response = Http::withHeaders($this->headers($sessionBearer))
            ->get(self::BASE_URL."/openapi/ipg/v2/{$market['market']}/queryTransactionStatus/", [
                'input_QueryReference' => $transaction->provider_transaction_id,
                'input_ServiceProviderCode' => $this->serviceProviderCode,
                'input_ThirdPartyConversationID' => $transaction->transaction_id,
                'input_Country' => $market['country'],
            ]);

        if (! $response->successful()) {
            $message = $response->json('output_ResponseDesc') ?? 'Unable to verify the transaction with M-Pesa';
            $statusCode = $response->status() >= 400 ? $response->status() : 502;

            return ApiResponse::error($message, $statusCode);
        }

        $providerStatus = (string) $response->json('output_ResponseTransactionStatus');
        $status = self::mapStatus($providerStatus);

        $transaction->update([
            'status' => $status,
            'provider_response' => $response->json(),
        ]);

        return ApiResponse::status($status->value, self::statusMessage($status), [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $status->value,
            'provider_status' => $providerStatus,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
        ]);
    }

    /**
     * Obtain a usable bearer: fetch a Session ID (authenticating with the
     * RSA-encrypted API key) and return the Session ID re-encrypted with the
     * public key. Cached per (api key, market) until shortly before expiry.
     */
    private function getSessionBearer(string $market): ?string
    {
        $cacheKey = 'mpesa_vodacom_session_'.sha1($this->apiKey.'|'.$market);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $apiKeyBearer = $this->encrypt($this->apiKey);
        if (! $apiKeyBearer) {
            return null;
        }

        $response = Http::withHeaders($this->headers($apiKeyBearer))
            ->get(self::BASE_URL."/openapi/ipg/v2/{$market}/getSession/");

        $sessionId = $response->json('output_SessionID');
        if (! $response->successful() || ! $sessionId || (string) $response->json('output_ResponseCode') !== 'INS-0') {
            return null;
        }

        $sessionBearer = $this->encrypt((string) $sessionId);
        if (! $sessionBearer) {
            return null;
        }

        // Open API sessions are valid for an hour; refresh shortly before that.
        Cache::put($cacheKey, $sessionBearer, now()->addMinutes(50));

        return $sessionBearer;
    }

    /**
     * RSA-encrypt a value with the platform public key (PKCS#1 v1.5), base64
     * encoded — the form the Open API expects for both the API key and session.
     */
    private function encrypt(string $value): ?string
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($this->publicKey, 64, "\n").'-----END PUBLIC KEY-----';

        $key = openssl_pkey_get_public($pem);
        if (! $key) {
            return null;
        }

        if (! openssl_public_encrypt($value, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
            return null;
        }

        return base64_encode($encrypted);
    }

    /**
     * The headers every Open API call requires, with the given bearer.
     *
     * @return array<string, string>
     */
    private function headers(string $bearer): array
    {
        return [
            'Authorization' => 'Bearer '.$bearer,
            'Origin' => '*',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * A short, unique alphanumeric reference (Open API ids must be alphanumeric).
     */
    private function newReference(): string
    {
        return Str::upper(Str::random(20));
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
     * Normalise a phone number to the international MSISDN the Open API expects
     * (country code, no leading zero, no '+', e.g. "255712345678").
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
     * Map an Open API transaction status onto our internal TransactionStatus.
     * Statuses: Completed | Failed | (anything else is still in progress).
     */
    private static function mapStatus(string $providerStatus): TransactionStatus
    {
        return match (strtolower($providerStatus)) {
            'completed' => TransactionStatus::SUCCESS,
            'failed' => TransactionStatus::FAILED,
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
