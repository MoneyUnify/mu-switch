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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class LencoController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'Lenco';

    public const DEFAULT_COUNTRIES = 'ZM,MW';

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
            'currency' => $accountType->country === 'MW' ? 'MWK' : 'ZMW',
            'status' => TransactionStatus::PENDING,
            'direction' => 'credit',
            'provider_transaction_id' => 'temp_'.Str::random(10),
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

            return ApiResponse::error($errorMessage, $response->status());
        }

        // Save real provider reference and update status
        $transaction->update([
            'provider_transaction_id' => $response->json('data.lencoReference'),
            'status' => TransactionStatus::PENDING,
            'provider_response' => $response->json(),
        ]);

        return ApiResponse::success('Payment request initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
        ]);
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
