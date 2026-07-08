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
use Illuminate\Support\Str;

/**
 * MobiPay driver — mobile-money push to pay in Malawi (the "Malipo" product).
 *
 * MobiPay's Malawi gateway (Malipo, `app.malipo.mw`) pushes a request-to-pay
 * prompt straight to the payer's Airtel Money or TNM Mpamba wallet: a single
 * JSON `POST /paymentrequest` (authenticated with an API key + app id) starts
 * the collection, and the status is polled with `GET /payment/enquire/{ref}`.
 * The mobile operator is chosen with a numeric `bankId` (1 = Airtel, 2 = TNM),
 * derived here from the payer's number so a call only needs country + phone.
 *
 * @see https://malipo.mw/documentation/
 */
class MobipayController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'MobiPay';

    /**
     * MobiPay's mobile-money push is a Malawi-only product.
     */
    public const SUPPORTED_COUNTRIES = ['MW'];

    public const DEFAULT_COUNTRIES = 'MW';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/mobipay.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
        ['key' => 'app_id', 'label' => 'App ID', 'type' => 'text'],
    ];

    /**
     * MobiPay's Malipo mobile-operator identifiers (the `bankId` field).
     *
     * @var array<string, int>
     */
    private const BANKS = ['airtel' => 1, 'tnm' => 2];

    /**
     * Local two-digit prefix (after the country code) => Malawi operator.
     * Airtel: 099x / 098x — TNM Mpamba: 088x.
     *
     * @var array<string, string>
     */
    private const OPERATOR_PREFIXES = [
        '99' => 'airtel',
        '98' => 'airtel',
        '88' => 'tnm',
    ];

    /**
     * MobiPay Malawi (Malipo) production host.
     */
    private const BASE_URL = 'https://app.malipo.mw/api/v1';

    private string $apiKey;

    private string $appId;

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $apiKey = $config['api_key'] ?? null;
        $appId = $config['app_id'] ?? null;

        if (! $apiKey || ! $appId) {
            return ApiResponse::error('API Key and App ID are required for the MobiPay provider', 400);
        }

        $this->apiKey = $apiKey;
        $this->appId = (string) $appId;
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate a mobile-money collection (push to pay) with MobiPay.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        if ($country !== 'MW') {
            return ApiResponse::error("MobiPay only supports mobile money in Malawi (MW), not {$country}", 422);
        }

        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);
        $bankId = $this->resolveBankId($msisdn, $request);

        $customer = $this->resolveCustomer($msisdn, $country, $request);
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

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'x-app-id' => $this->appId,
        ])->acceptJson()->post(self::BASE_URL.'/paymentrequest', [
            'merchantTrxId' => $reference,
            'customerPhone' => $msisdn,
            'bankId' => $bankId,
            'amount' => (int) round((float) $request['amount']),
        ]);

        // A 2xx acknowledges the push was sent; the collection stays pending
        // until the payer approves it on their handset.
        if (! $response->successful()) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json() ?: ['body' => $response->body()],
            ]);
            $message = $response->json('message') ?? 'MobiPay payment request failed';

            return ApiResponse::error($message, $response->status() >= 400 ? $response->status() : 422);
        }

        $transaction->update([
            'provider_transaction_id' => (string) ($response->json('data.transaction_id') ?? $reference),
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
     * Re-check a transaction with MobiPay and persist its latest status.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        // MobiPay's enquiry is keyed on our merchant transaction reference.
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'x-app-id' => $this->appId,
        ])->acceptJson()->get(self::BASE_URL.'/payment/enquire/'.$transaction->transaction_id);

        if (! $response->successful()) {
            $message = $response->json('message') ?? 'Unable to verify the transaction with MobiPay';

            return ApiResponse::error($message, $response->status() >= 400 ? $response->status() : 502);
        }

        $providerStatus = (string) ($response->json('data.status') ?? $response->json('status'));
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
     * The MobiPay operator id (`bankId`) for a payment — from the caller's
     * explicit `bank_id`/`operator` if given, otherwise inferred from the
     * number's dialling prefix, falling back to Airtel (the larger network).
     */
    private function resolveBankId(string $msisdn, Request $request): int
    {
        $givenBank = (int) $request->input('bank_id');
        if (in_array($givenBank, self::BANKS, true)) {
            return $givenBank;
        }

        $givenOperator = strtolower(trim((string) $request->input('operator')));
        if (isset(self::BANKS[$givenOperator])) {
            return self::BANKS[$givenOperator];
        }

        $callingCode = (string) Market::callingCode('MW');
        $local = str_starts_with($msisdn, $callingCode) ? substr($msisdn, strlen($callingCode)) : $msisdn;
        $operator = self::OPERATOR_PREFIXES[substr($local, 0, 2)] ?? 'airtel';

        return self::BANKS[$operator];
    }

    /**
     * A unique transaction reference.
     */
    private function newReference(): string
    {
        return 'MU-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }

    /**
     * Find or create the payer's customer record idempotently.
     */
    private function resolveCustomer(string $msisdn, string $country, Request $request): Customer
    {
        $email = trim((string) $request->input('email')) ?: $msisdn.'@moneyunify.local';
        $name = trim(trim((string) $request->input('first_name')).' '.trim((string) $request->input('last_name'))) ?: 'Unnamed Customer';

        return DB::transaction(function () use ($msisdn, $country, $email, $name): Customer {
            $customer = Customer::firstOrCreate(
                ['email' => $email],
                ['name' => $name],
            );

            CustomerAccount::updateOrCreate(
                ['number' => $msisdn, 'country' => $country],
                ['customer_id' => $customer->id, 'name' => $customer->name],
            );

            return $customer;
        });
    }

    /**
     * Normalise a phone number to the international MSISDN MobiPay expects
     * (country code, no leading zero, no '+', e.g. "265994791131").
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
     * Map a MobiPay enquiry status onto our internal TransactionStatus.
     * MobiPay statuses: Completed | Failed (anything else is still in flight).
     */
    private static function mapStatus(string $providerStatus): TransactionStatus
    {
        return match (strtolower($providerStatus)) {
            'completed', 'success', 'successful' => TransactionStatus::SUCCESS,
            'failed', 'declined', 'cancelled' => TransactionStatus::FAILED,
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
