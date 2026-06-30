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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * M-Pesa (Kenya) driver — Safaricom Daraja STK Push (Lipa Na M-Pesa Online).
 *
 * Safaricom's Daraja API authenticates with OAuth client-credentials (Consumer
 * Key + Secret exchanged for a bearer token) and initiates a customer push with
 * an STK Push request signed by the shortcode passkey. All of that is handled
 * privately here, so a merchant only configures their Daraja credentials.
 *
 * @see https://developer.safaricom.co.ke/APIs/MpesaExpressSimulate
 */
class MpesaController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'M-Pesa (Kenya)';

    /**
     * Safaricom M-Pesa operates in Kenya only.
     */
    public const SUPPORTED_COUNTRIES = ['KE'];

    public const DEFAULT_COUNTRIES = 'KE';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/mpesa.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'consumer_key', 'label' => 'Consumer Key', 'type' => 'text'],
        ['key' => 'consumer_secret', 'label' => 'Consumer Secret', 'type' => 'password'],
        ['key' => 'shortcode', 'label' => 'Business Short Code (Paybill)', 'type' => 'text'],
        ['key' => 'passkey', 'label' => 'Lipa Na M-Pesa Passkey', 'type' => 'password'],
    ];

    /**
     * Safaricom Daraja production host (we run providers in production only).
     */
    private const BASE_URL = 'https://api.safaricom.co.ke';

    private string $consumerKey;

    private string $consumerSecret;

    private string $shortcode;

    private string $passkey;

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $consumerKey = $config['consumer_key'] ?? null;
        $consumerSecret = $config['consumer_secret'] ?? null;
        $shortcode = $config['shortcode'] ?? null;
        $passkey = $config['passkey'] ?? null;

        if (! $consumerKey || ! $consumerSecret || ! $shortcode || ! $passkey) {
            return ApiResponse::error('Consumer Key, Consumer Secret, Short Code and Passkey are required for the M-Pesa provider', 400);
        }

        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->shortcode = (string) $shortcode;
        $this->passkey = $passkey;
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate an STK Push (M-Pesa Express) prompt on the payer's handset.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

        $token = $this->getAccessToken();
        if (! $token) {
            return ApiResponse::error('Could not authenticate with M-Pesa', 502);
        }

        $customer = $this->resolveCustomer($msisdn, $country);

        $reference = (string) Str::uuid();
        $timestamp = Carbon::now()->format('YmdHis');

        $transaction = new Transaction([
            'transaction_id' => $reference,
            'customer_id' => $customer->id,
            'amount' => $request['amount'],
            'currency' => $currency,
            'country' => $country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'credit',
            'provider_transaction_id' => 'temp_'.Str::random(10),
            'callback_url' => $request->input('callback_url'),
        ]);
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(self::BASE_URL.'/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $this->shortcode,
                'Password' => base64_encode($this->shortcode.$this->passkey.$timestamp),
                'Timestamp' => $timestamp,
                'TransactionType' => 'CustomerPayBillOnline',
                'Amount' => (int) round((float) $request['amount']),
                'PartyA' => $msisdn,
                'PartyB' => $this->shortcode,
                'PhoneNumber' => $msisdn,
                'CallBackURL' => $this->callbackUrl($request),
                'AccountReference' => substr($reference, 0, 12),
                'TransactionDesc' => 'Payment collection',
            ]);

        // Daraja accepts an STK Push with HTTP 200 and ResponseCode "0".
        $accepted = $response->successful() && (string) $response->json('ResponseCode') === '0';

        if (! $accepted) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json() ?: ['body' => $response->body()],
            ]);
            $message = $response->json('errorMessage') ?? $response->json('ResponseDescription') ?? 'M-Pesa STK Push request failed';
            $statusCode = $response->status() >= 400 ? $response->status() : 422;

            return ApiResponse::error($message, $statusCode);
        }

        $transaction->update([
            // Daraja identifies the prompt by CheckoutRequestID, used to verify it.
            'provider_transaction_id' => $response->json('CheckoutRequestID') ?? $transaction->provider_transaction_id,
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
     * Re-check an STK Push with Daraja and persist its latest status.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $token = $this->getAccessToken();
        if (! $token) {
            return ApiResponse::error('Could not authenticate with M-Pesa', 502);
        }

        $timestamp = Carbon::now()->format('YmdHis');

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(self::BASE_URL.'/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $this->shortcode,
                'Password' => base64_encode($this->shortcode.$this->passkey.$timestamp),
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $transaction->provider_transaction_id,
            ]);

        $resultCode = $response->json('ResultCode');
        $errorCode = (string) $response->json('errorCode');

        // While the customer is still being prompted, Daraja replies with
        // errorCode 500.001.1001 ("transaction is being processed").
        if ($resultCode === null) {
            if (str_contains($errorCode, '500.001.1001')) {
                return $this->persistAndRespond($transaction, TransactionStatus::PENDING, 'processing', $response->json());
            }

            $message = $response->json('errorMessage') ?? 'Unable to verify the transaction with M-Pesa';
            $statusCode = $response->status() >= 400 ? $response->status() : 502;

            return ApiResponse::error($message, $statusCode);
        }

        // A settled prompt carries a ResultCode: "0" succeeded, anything else
        // (1032 cancelled, 1037 timeout, 1 insufficient funds, …) failed.
        $status = (string) $resultCode === '0' ? TransactionStatus::SUCCESS : TransactionStatus::FAILED;

        return $this->persistAndRespond($transaction, $status, (string) $resultCode, $response->json());
    }

    /**
     * Persist the resolved status and return the outcome-mirroring envelope.
     *
     * @param  array<string, mixed>|null  $providerResponse
     */
    private function persistAndRespond(Transaction $transaction, TransactionStatus $status, string $providerStatus, ?array $providerResponse): JsonResponse
    {
        $transaction->update([
            'status' => $status,
            'provider_response' => $providerResponse,
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
     * Exchange the Consumer Key / Secret for a bearer access token.
     *
     * The token is cached until shortly before it expires so we don't re-fetch
     * one on every call.
     */
    private function getAccessToken(): ?string
    {
        $cacheKey = 'mpesa_ke_access_token_'.sha1($this->consumerKey);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $response = Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->acceptJson()
            ->get(self::BASE_URL.'/oauth/v1/generate', ['grant_type' => 'client_credentials']);

        $token = $response->json('access_token');
        if (! $response->successful() || ! $token) {
            return null;
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);
        Cache::put($cacheKey, $token, now()->addSeconds(max(60, $expiresIn - 60)));

        return $token;
    }

    /**
     * The HTTPS callback URL Daraja requires (we still verify via query). Falls
     * back to an app URL when the caller supplies none.
     */
    private function callbackUrl(Request $request): string
    {
        return $request->input('callback_url') ?: rtrim((string) config('app.url'), '/').'/api/v1/payment/mpesa/callback';
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
     * Normalise a phone number to the international MSISDN Daraja expects
     * (country code, no leading zero, no '+', e.g. "254712345678").
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
