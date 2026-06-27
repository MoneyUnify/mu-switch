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
 * Airtel Money driver.
 *
 * Airtel's Open API uses OAuth2 client-credentials: you exchange a Client ID and
 * Client Secret for a short-lived bearer access token, then call the collection
 * and status endpoints with it. All of that token handling is kept private here,
 * so a merchant only ever configures a **Client ID** and **Client Secret** in
 * the dashboard.
 */
class AirtelController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'Airtel Money';

    /**
     * Airtel Africa's markets (selectable in the dashboard).
     */
    public const SUPPORTED_COUNTRIES = ['NG', 'KE', 'TZ', 'UG', 'RW', 'ZM', 'MW', 'CD', 'CG', 'GA', 'TD', 'NE', 'MG', 'SC'];

    public const DEFAULT_COUNTRIES = 'ZM,MW';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/airtel.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
        ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
    ];

    private string $clientId;

    private string $clientSecret;

    public ?PaymentProvider $provider = null;

    private readonly string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://openapi.airtel.africa';
    }

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $clientId = $config['client_id'] ?? null;
        $clientSecret = $config['client_secret'] ?? null;

        if (! $clientId || ! $clientSecret) {
            return ApiResponse::error('Client ID and Client Secret are required for the Airtel Money provider', 400);
        }

        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate a collection (USSD push) with Airtel Money.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

        $token = $this->getAccessToken();
        if (! $token) {
            return ApiResponse::error('Could not authenticate with Airtel Money', 502);
        }

        $customer = $this->resolveCustomer($msisdn, $country);

        $reference = (string) Str::uuid();

        $transaction = new Transaction([
            'transaction_id' => $reference,
            'customer_id' => $customer->id,
            'amount' => $request['amount'],
            'currency' => $currency,
            'country' => $country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'credit',
            'provider_transaction_id' => $reference,
            // Optional: where the switch posts the final result once verified.
            'callback_url' => $request->input('callback_url'),
        ]);
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        $response = Http::withToken($token)
            ->withHeaders($this->countryHeaders($country))
            ->acceptJson()
            ->post($this->baseUrl.'/merchant/v1/payments/', [
                'reference' => $reference,
                'subscriber' => [
                    'country' => $country,
                    'currency' => $currency,
                    'msisdn' => $msisdn,
                ],
                'transaction' => [
                    'amount' => (float) $request['amount'],
                    'country' => $country,
                    'currency' => $currency,
                    'id' => $reference,
                ],
            ]);

        // Airtel acknowledges an initiated push with status.success = true and an
        // in-progress (TIP) transaction status; treat anything else as a failure.
        $initiated = $response->successful() && $response->json('status.success') === true;

        if (! $initiated) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json(),
            ]);
            $message = $response->json('status.message') ?? 'Airtel Money payment request failed';
            $statusCode = $response->status() >= 400 ? $response->status() : 422;

            return ApiResponse::error($message, $statusCode);
        }

        $transaction->update([
            'provider_transaction_id' => $response->json('data.transaction.id') ?? $reference,
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
     * Re-check a transaction with Airtel Money and persist its latest status.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $country = (string) ($transaction->country ?: Market::countryForCurrency($transaction->currency));

        $token = $this->getAccessToken();
        if (! $token) {
            return ApiResponse::error('Could not authenticate with Airtel Money', 502);
        }

        $response = Http::withToken($token)
            ->withHeaders($this->countryHeaders($country))
            ->acceptJson()
            ->get($this->baseUrl.'/standard/v1/payments/'.$transaction->transaction_id);

        if (! $response->successful()) {
            $message = $response->json('status.message') ?? 'Unable to verify the transaction with Airtel Money';
            $statusCode = $response->status() >= 400 ? $response->status() : 502;

            return ApiResponse::error($message, $statusCode);
        }

        $providerStatus = (string) $response->json('data.transaction.status');
        $status = self::mapStatus($providerStatus);

        $transaction->update([
            'status' => $status,
            'provider_response' => $response->json(),
            'provider_transaction_id' => $response->json('data.transaction.airtel_money_id') ?? $transaction->provider_transaction_id,
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
     * Exchange the Client ID / Secret for a bearer access token.
     *
     * The token is cached until shortly before it expires so we don't re-fetch
     * one on every call.
     */
    private function getAccessToken(): ?string
    {
        $cacheKey = 'airtel_access_token_'.sha1($this->clientId);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $response = Http::acceptJson()
            ->asJson()
            ->post($this->baseUrl.'/auth/oauth2/token', [
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'grant_type' => 'client_credentials',
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
     * The country/currency headers every Airtel Money call requires.
     *
     * @return array<string, string>
     */
    private function countryHeaders(string $country): array
    {
        return [
            'X-Country' => $country,
            'X-Currency' => (string) Market::currency($country),
        ];
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
     * Normalise a phone number to the local MSISDN Airtel expects
     * (no leading zero, no country calling code).
     */
    private function normaliseMsisdn(string $number, string $country): string
    {
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $digits = ltrim($digits, '0');

        $callingCode = Market::callingCode($country);

        if ($callingCode && str_starts_with($digits, $callingCode)) {
            return substr($digits, strlen($callingCode));
        }

        return $digits;
    }

    /**
     * Map an Airtel transaction status onto our internal TransactionStatus.
     * Airtel statuses: TS (success) | TF (failed) | TIP (in progress / pending).
     */
    private static function mapStatus(string $providerStatus): TransactionStatus
    {
        return match ($providerStatus) {
            'TS' => TransactionStatus::SUCCESS,
            'TF' => TransactionStatus::FAILED,
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
