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
 * MTN MoMo (Mobile Money) driver — Collections product.
 *
 * MTN's MoMo API authenticates in two layers: a Collections **subscription key**
 * identifies the product, and an **API User** + **API Key** pair are exchanged
 * (via Basic auth) for a short-lived bearer access token. All of that is handled
 * privately here, so a merchant only configures their MoMo credentials in the
 * dashboard.
 */
class MtnController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'MTN MoMo';

    /**
     * MTN MoMo's markets (selectable in the dashboard).
     */
    public const SUPPORTED_COUNTRIES = ['ZM', 'UG', 'GH', 'CI', 'CM', 'RW', 'BJ', 'GN', 'GW', 'LR', 'CG', 'NG', 'ZA', 'SZ', 'SS'];

    /**
     * Markets pre-ticked when adding the provider.
     */
    public const DEFAULT_COUNTRIES = 'ZM';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/mtn.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'subscription_key', 'label' => 'Collections Subscription Key', 'type' => 'password'],
        ['key' => 'api_user', 'label' => 'API User ID', 'type' => 'text'],
        ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
    ];

    /**
     * MTN's production target environment (X-Target-Environment) per country.
     * Add markets here as you enable them.
     *
     * @var array<string, string>
     */
    private const TARGET_ENVIRONMENTS = [
        'ZM' => 'mtnzambia',
        'UG' => 'mtnuganda',
        'GH' => 'mtnghana',
        'CI' => 'mtnivorycoast',
        'CM' => 'mtncameroon',
        'RW' => 'mtnrwanda',
        'BJ' => 'mtnbenin',
        'GN' => 'mtnguineaconakry',
        'GW' => 'mtnguineabissau',
        'LR' => 'mtnliberia',
        'CG' => 'mtncongo',
        'NG' => 'mtnnigeria',
        'ZA' => 'mtnsouthafrica',
        'SZ' => 'mtnswaziland',
        'SS' => 'mtnsouthsudan',
    ];

    /**
     * MTN MoMo production host (we run providers in production only).
     */
    private const BASE_URL = 'https://proxy.momoapi.mtn.com';

    private string $subscriptionKey;

    private string $apiUser;

    private string $apiKey;

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $subscriptionKey = $config['subscription_key'] ?? null;
        $apiUser = $config['api_user'] ?? null;
        $apiKey = $config['api_key'] ?? null;

        if (! $subscriptionKey || ! $apiUser || ! $apiKey) {
            return ApiResponse::error('Subscription Key, API User and API Key are required for the MTN MoMo provider', 400);
        }

        $this->subscriptionKey = $subscriptionKey;
        $this->apiUser = $apiUser;
        $this->apiKey = $apiKey;
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate a collection (request-to-pay) with MTN MoMo.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

        $targetEnvironment = $this->targetEnvironmentFor($country);
        if (! $targetEnvironment) {
            return ApiResponse::error("MTN MoMo does not support payments in {$country}", 422);
        }

        $token = $this->getAccessToken($targetEnvironment);
        if (! $token) {
            return ApiResponse::error('Could not authenticate with MTN MoMo', 502);
        }

        $customer = $this->resolveCustomer($msisdn, $country);

        // MTN identifies a collection by the X-Reference-Id we generate (a UUID),
        // which we also use as our own transaction_id.
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
            ->withHeaders($this->momoHeaders($targetEnvironment, ['X-Reference-Id' => $reference]))
            ->acceptJson()
            ->post(self::BASE_URL.'/collection/v1_0/requesttopay', [
                'amount' => (string) $request['amount'],
                'currency' => $currency,
                'externalId' => $reference,
                'payer' => ['partyIdType' => 'MSISDN', 'partyId' => $msisdn],
                'payerMessage' => 'Payment collection',
                'payeeNote' => 'Collection '.$reference,
            ]);

        // MTN acknowledges an accepted request-to-pay with HTTP 202 (no body).
        if (! $response->successful()) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json() ?: ['body' => $response->body()],
            ]);
            $message = $response->json('message') ?? 'MTN MoMo payment request failed';
            $statusCode = $response->status() >= 400 ? $response->status() : 422;

            return ApiResponse::error($message, $statusCode);
        }

        return ApiResponse::success('Payment request initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
        ]);
    }

    /**
     * Re-check a transaction with MTN MoMo and persist its latest status.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $country = (string) ($transaction->country ?: Market::countryForCurrency($transaction->currency));
        $targetEnvironment = $this->targetEnvironmentFor($country);
        if (! $targetEnvironment) {
            return ApiResponse::error('MTN MoMo does not support this transaction\'s country', 422);
        }

        $token = $this->getAccessToken($targetEnvironment);
        if (! $token) {
            return ApiResponse::error('Could not authenticate with MTN MoMo', 502);
        }

        $response = Http::withToken($token)
            ->withHeaders($this->momoHeaders($targetEnvironment))
            ->acceptJson()
            ->get(self::BASE_URL.'/collection/v1_0/requesttopay/'.$transaction->transaction_id);

        if (! $response->successful()) {
            $message = $response->json('message') ?? 'Unable to verify the transaction with MTN MoMo';
            $statusCode = $response->status() >= 400 ? $response->status() : 502;

            return ApiResponse::error($message, $statusCode);
        }

        $providerStatus = (string) $response->json('status');
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
     * Exchange the API User / API Key (Basic auth) for a bearer access token.
     *
     * In production MTN requires the subscription key and the target environment
     * on the token request too. The token is cached until shortly before it
     * expires so we don't re-fetch one on every call.
     */
    private function getAccessToken(string $targetEnvironment): ?string
    {
        $cacheKey = 'mtn_access_token_'.sha1($this->apiUser.'|'.$this->subscriptionKey.'|'.$targetEnvironment);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $response = Http::withBasicAuth($this->apiUser, $this->apiKey)
            ->withHeaders([
                'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
                'X-Target-Environment' => $targetEnvironment,
            ])
            ->acceptJson()
            ->post(self::BASE_URL.'/collection/token/');

        $token = $response->json('access_token');
        if (! $response->successful() || ! $token) {
            return null;
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        Cache::put($cacheKey, $token, now()->addSeconds(max(60, $expiresIn - 60)));

        return $token;
    }

    /**
     * The headers every MoMo Collections call requires, plus any extras.
     * The target environment is derived from the country, not configured.
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function momoHeaders(string $targetEnvironment, array $extra = []): array
    {
        return array_merge([
            'Ocp-Apim-Subscription-Key' => $this->subscriptionKey,
            'X-Target-Environment' => $targetEnvironment,
        ], $extra);
    }

    /**
     * The MTN production target environment for a country, or null if MTN MoMo
     * isn't available there.
     */
    private function targetEnvironmentFor(string $country): ?string
    {
        return self::TARGET_ENVIRONMENTS[strtoupper($country)] ?? null;
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
     * Normalise a phone number to the international MSISDN MTN expects
     * (country code, no leading zero, no '+').
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

    private function currencyFor(string $country): string
    {
        return $country === 'MW' ? 'MWK' : 'ZMW';
    }

    /**
     * Map an MTN MoMo status onto our internal TransactionStatus.
     * MTN statuses: SUCCESSFUL | FAILED | PENDING.
     */
    private static function mapStatus(string $providerStatus): TransactionStatus
    {
        return match (strtoupper($providerStatus)) {
            'SUCCESSFUL' => TransactionStatus::SUCCESS,
            'FAILED' => TransactionStatus::FAILED,
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
