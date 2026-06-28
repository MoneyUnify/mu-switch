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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Lipila driver — Mobile Money Collections.
 *
 * Lipila is a Zambian aggregator (MTN, Airtel, Zamtel mobile money). It uses a
 * single secret API key passed as the `x-api-key` header — there is no token
 * exchange — so a merchant only configures their Lipila API key in the dashboard.
 *
 * @see https://docs.lipila.dev/docs/collections/momocollections.html
 * @see https://docs.lipila.dev/docs/collections/collection-status.html
 */
class LipilaController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'Lipila';

    /**
     * Lipila operates in Zambia only.
     */
    public const SUPPORTED_COUNTRIES = ['ZM'];

    public const DEFAULT_COUNTRIES = 'ZM';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/lipila.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
    ];

    /**
     * Lipila production host.
     */
    private const BASE_URL = 'https://blz.lipila.io';

    private string $apiKey;

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $apiKey = $config['api_key'] ?? null;

        if (! $apiKey) {
            return ApiResponse::error('API key is required for the Lipila provider', 400);
        }

        $this->apiKey = trim($apiKey);
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate a mobile-money collection (request to pay) with Lipila.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

        $customer = $this->resolveCustomer($msisdn, $country);

        // Lipila identifies a collection by the referenceId we generate, which we
        // also use as our own transaction_id so verification can look it up.
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
            // Optional: where the switch posts the final result once verified.
            'callback_url' => $request->input('callback_url'),
        ]);
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        // Body shape per Lipila (Blaze) collections API.
        $payload = [
            'referenceId' => $reference,
            'currency' => $currency,
            'amount' => (float) $request['amount'],
            'accountNumber' => $msisdn,
            'narration' => trim((string) $request->input('narration')) ?: 'Payment collection',
        ];

        // Email is optional — only forward it when the caller supplied one.
        $email = trim((string) $request->input('email'));
        if ($email !== '') {
            $payload['email'] = $email;
        }

        $response = Http::withHeaders($this->headers())
            ->asJson()
            ->post(self::BASE_URL.'/api/v1/collections/mobile-money', $payload);

        // Lipila accepts a collection with HTTP 200 and a non-failed status
        // ("Pending" while the payer authorises on their handset). Treat a
        // failed HTTP code or an explicit "Failed" status as a failure.
        $providerStatus = (string) $response->json('status');
        $accepted = $response->successful() && strcasecmp($providerStatus, 'Failed') !== 0;

        if (! $accepted) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json() ?: ['body' => $response->body()],
            ]);
            $message = $response->json('message') ?? 'Lipila payment request failed';
            $statusCode = $response->status() >= 400 ? $response->status() : 422;

            return ApiResponse::error($message, $statusCode);
        }

        $transaction->update([
            // Lipila's own identifier for the transaction (e.g. "LPLXC-...").
            'provider_transaction_id' => $response->json('identifier') ?? $reference,
            'status' => self::mapStatus($providerStatus),
            'provider_response' => $response->json(),
        ]);

        return ApiResponse::success('Payment request initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
        ]);
    }

    /**
     * Re-check a transaction with Lipila and persist its latest status.
     *
     * Lipila looks a collection up by the referenceId we sent, which is our own
     * transaction_id.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $response = Http::withHeaders($this->headers())
            ->get(self::BASE_URL.'/api/v1/collections/check-status', [
                'referenceId' => $transaction->transaction_id,
            ]);

        if (! $response->successful()) {
            $message = $response->json('message') ?? 'Unable to verify the transaction with Lipila';
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
     * The headers every Lipila call requires.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'accept' => 'application/json',
            'Content-Type' => 'application/json',
            'x-api-key' => $this->apiKey,
        ];
    }

    /**
     * A short, unique reference id (12 hex characters) in Lipila's format.
     */
    private function newReference(): string
    {
        return bin2hex(random_bytes(6));
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
     * Normalise a phone number to the international account number Lipila expects
     * (country code, no leading zero, no '+', e.g. "260977123456").
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
     * Map a Lipila collection status onto our internal TransactionStatus.
     * Lipila statuses: Pending | Successful | Failed.
     */
    private static function mapStatus(string $providerStatus): TransactionStatus
    {
        return match (strtolower($providerStatus)) {
            'successful' => TransactionStatus::SUCCESS,
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
