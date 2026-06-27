<?php

namespace App\Http\Controllers\Providers;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\DataTypes\TypeAccountNumber;
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

class LencoController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'Lenco';

    /**
     * Markets Lenco can serve (selectable in the dashboard).
     */
    public const SUPPORTED_COUNTRIES = ['ZM', 'MW'];

    public const DEFAULT_COUNTRIES = 'ZM,MW';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/lenco.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'api_key', 'label' => 'API Key / Token', 'type' => 'password'],
    ];

    public string $api_key {
        set(string $value) => trim($value);
    }

    public $provider;

    private readonly string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://api.lenco.co/access/v2';
    }

    /**
     * This fulfills the contract requirement so SwitchController can pass the data
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = $provider->config;
        if (is_string($config)) {
            $config = json_decode($config, true);
        }
        $apiKey = $config['api_key'] ?? null;
        if (! $apiKey) {
            return ApiResponse::error('API key is required for Lenco provider', 400);
        }
        $this->api_key = $apiKey;
        $this->provider = $provider;

        return null;
    }

    public function requestPayment(Request $request): JsonResponse
    {

        $accountType = self::getTypeAccount($request['account_number']);
        if (! $accountType) {
            return ApiResponse::error('Unsupported mobile operator', 400);
        }
        // Resolve (or create) the customer and their account idempotently so
        // retried or repeated requests for the same number never collide on the
        // unique customer email. Wrapped in a transaction to avoid orphans.
        $customer = DB::transaction(function () use ($accountType) {
            $email = $accountType->number.'@moneyunify.local';
            $resolvedName = $accountType->name ?? 'Customer '.$accountType->number;

            $customer = Customer::firstOrCreate(
                ['email' => $email],
                ['name' => $resolvedName],
            );

            // Keep the stored name fresh when the provider resolves a real one.
            if ($accountType->name && $customer->name !== $accountType->name) {
                $customer->update(['name' => $accountType->name]);
            }

            CustomerAccount::updateOrCreate(
                ['number' => $accountType->number, 'country' => $accountType->country],
                ['customer_id' => $customer->id, 'name' => $resolvedName],
            );

            return $customer;
        });

        // generate reference
        $reference = (string) Str::uuid();

        // start transaction processing
        $transaction = new Transaction([
            'transaction_id' => $reference,
            'customer_id' => $customer->id,
            'amount' => $request['amount'],
            'currency' => Market::currency($accountType->country),
            'country' => $accountType->country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'credit',
            'provider_transaction_id' => 'temp_'.Str::random(10),
            'callback_url' => $request->input('callback_url'),
        ]);
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        $url = $this->baseUrl.'/collections/mobile-money';

        // Only call Lenco API with mandatory required fields: amount, reference, phone, operator
        $response = Http::withToken($this->api_key)
            ->post($url, [
                'amount' => (float) $request['amount'],
                'reference' => $reference,
                'phone' => $accountType->number,
                'operator' => $accountType->operator,
            ]);

        if (! $response->successful() || ! $response->json('status')) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json(),
            ]);
            $errorMessage = $response->json('message') ?? 'Lenco API payment request failed';

            // A provider can decline with HTTP 200 + status:false. Never surface
            // that as a 2xx: our standard requires errors to carry a 4xx/5xx code,
            // and the switch relies on a non-200 code to fall back to the next provider.
            $statusCode = $response->status() >= 400 ? $response->status() : 422;

            return ApiResponse::error($errorMessage, $statusCode);
        }

        // Save real provider reference and update status
        $transaction->update([
            'provider_transaction_id' => $response->json('data.lencoReference'),
            'status' => TransactionStatus::PENDING,
            'provider_response' => $response->json(),
        ]);

        // The collection is initiated but not yet settled — mobile money is
        // asynchronous, so the transaction starts as `pending`. Surface that so
        // the caller knows to verify for the final outcome.
        return ApiResponse::success('Payment request initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
        ]);
    }

    /**
     * Verify a transaction with Lenco and persist its latest status.
     *
     * Lenco identifies a collection by the reference we sent it, which is our
     * own transaction_id. See the "get collection by reference" endpoint:
     * https://lenco-api.readme.io/v2.0/reference/get-collection-by-reference
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $response = Http::withToken($this->api_key)
            ->acceptJson()
            ->get($this->baseUrl.'/collections/status/'.$transaction->transaction_id);

        if (! $response->successful() || ! $response->json('status')) {
            $message = $response->json('message') ?? 'Unable to verify the transaction with Lenco';

            // Verification couldn't be completed — always a 4xx/5xx, never a 2xx.
            $statusCode = $response->status() >= 400 ? $response->status() : 502;

            return ApiResponse::error($message, $statusCode);
        }

        $providerStatus = (string) $response->json('data.status');
        $status = self::mapStatus($providerStatus);

        $transaction->update([
            'status' => $status,
            'provider_response' => $response->json(),
        ]);

        // The envelope status mirrors the transaction's real outcome
        // (success / failed / pending) so it is never confused with "the API
        // call worked". The HTTP code remains 200 because verification succeeded.
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
     * Map a Lenco collection status onto our internal TransactionStatus.
     * Lenco statuses: pending | successful | failed | pay-offline | 3ds-auth-required.
     */
    private static function mapStatus(string $providerStatus): TransactionStatus
    {
        return match ($providerStatus) {
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

    private function getTypeAccount(string $accountNumber): ?TypeAccountNumber
    {
        $supported_operators = [
            'mtn' => ['76', '96', '56'],
            'airtel' => ['77', '97', '57', '89'], // 89 is malawi airtel operator
            'zamtel' => ['75', '95', '55'],
            'tnm' => ['88'],
        ];
        $operator_code = substr(intval($accountNumber), 0, 2);
        $operator = false;
        foreach ($supported_operators as $operator_key => $numbers) {
            if (in_array($operator_code, $numbers)) {
                $operator = $operator_key;
                break;
            }
        }
        if (! $operator) {
            return null;
        }
        $country = $operator === 'tnm' ? 'MW' : ($operator_code === '89' ? 'MW' : 'ZM');
        $account_name = null;
        if (in_array($country, ['ZM', 'MW'])) {
            $accountExists = CustomerAccount::where('number', $accountNumber)
                ->where('country', $country)
                ->whereNotNull('name')
                ->first();
            if ($accountExists) {
                $account_name = $accountExists->name;
            } else {
                $resolveAccount = Http::withToken($this->api_key)
                    ->post($this->baseUrl.'/resolve/mobile-money', [
                        'phone' => $accountNumber,
                        'country' => strtolower($country),
                        'operator' => $operator,
                    ]);
                if ($resolveAccount->successful() && $resolveAccount->json('status')) {
                    $account_name = $resolveAccount->json('data.accountName');
                }
            }
        }
        $account_name = $account_name ?? $accountNumber;

        return new TypeAccountNumber($operator, $accountNumber, $account_name, $country);
    }
}
