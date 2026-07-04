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
 * Flutterwave driver — v3 mobile-money collections (push to pay).
 *
 * Flutterwave authenticates with a single Bearer secret key and initiates a
 * mobile-money charge per market via `POST /v3/charges?type=<type>` — an STK/USSD
 * prompt to the payer. The `type` (and, for some markets, a mobile `network`)
 * are derived here from the request's country, so a merchant only configures
 * their secret key and a call only needs country + phone number.
 *
 * @see https://developer.flutterwave.com/docs/mobile-money
 */
class FlutterwaveController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'Flutterwave';

    /**
     * Per-country Flutterwave charge configuration:
     * type = the `?type=` charge value; network = operator networks Flutterwave
     * requires for that market (empty = none required).
     *
     * @var array<string, array{type: string, network: list<string>, franco: bool}>
     */
    private const MARKETS = [
        'KE' => ['type' => 'mpesa', 'network' => [], 'franco' => false],
        'GH' => ['type' => 'mobile_money_ghana', 'network' => ['MTN', 'VODAFONE', 'TIGO'], 'franco' => false],
        'UG' => ['type' => 'mobile_money_uganda', 'network' => ['MTN', 'AIRTEL'], 'franco' => false],
        'RW' => ['type' => 'mobile_money_rwanda', 'network' => [], 'franco' => false],
        'ZM' => ['type' => 'mobile_money_zambia', 'network' => ['MTN', 'AIRTEL', 'ZAMTEL'], 'franco' => false],
        'TZ' => ['type' => 'mobile_money_tanzania', 'network' => [], 'franco' => false],
        'CM' => ['type' => 'mobile_money_franco', 'network' => [], 'franco' => true],
        'CI' => ['type' => 'mobile_money_franco', 'network' => [], 'franco' => true],
    ];

    /**
     * Local dialling-prefix => operator, used to pick the `network` for the
     * markets that require one. Prefix is "0" + the first two significant digits.
     *
     * @var array<string, array<string, string>>
     */
    private const NETWORK_PREFIXES = [
        'GH' => [
            '024' => 'MTN', '054' => 'MTN', '055' => 'MTN', '059' => 'MTN', '025' => 'MTN', '053' => 'MTN',
            '020' => 'VODAFONE', '050' => 'VODAFONE',
            '027' => 'TIGO', '057' => 'TIGO', '026' => 'TIGO', '056' => 'TIGO', '023' => 'TIGO',
        ],
        'UG' => [
            '077' => 'MTN', '078' => 'MTN', '076' => 'MTN', '039' => 'MTN', '031' => 'MTN',
            '070' => 'AIRTEL', '075' => 'AIRTEL', '074' => 'AIRTEL', '020' => 'AIRTEL', '073' => 'AIRTEL',
        ],
        'ZM' => [
            '096' => 'MTN', '076' => 'MTN',
            '097' => 'AIRTEL', '077' => 'AIRTEL',
            '095' => 'ZAMTEL', '075' => 'ZAMTEL',
        ],
    ];

    /**
     * Markets Flutterwave mobile money can serve (selectable in the dashboard).
     */
    public const SUPPORTED_COUNTRIES = ['KE', 'GH', 'UG', 'RW', 'ZM', 'TZ', 'CM', 'CI'];

    public const DEFAULT_COUNTRIES = 'KE';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/flutterwave.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
    ];

    /**
     * Flutterwave v3 production host.
     */
    private const BASE_URL = 'https://api.flutterwave.com/v3';

    private string $secretKey;

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $secretKey = $config['secret_key'] ?? null;

        if (! $secretKey) {
            return ApiResponse::error('Secret Key is required for the Flutterwave provider', 400);
        }

        $this->secretKey = $secretKey;
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate a mobile-money charge (push to pay) with Flutterwave.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $market = self::MARKETS[$country] ?? null;
        if (! $market) {
            return ApiResponse::error("Flutterwave does not support mobile money in {$country}", 422);
        }

        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

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

        $payload = array_filter([
            'tx_ref' => $reference,
            'amount' => (float) $request['amount'],
            'currency' => $currency,
            'email' => $customer->email,
            'phone_number' => $msisdn,
            'fullname' => $customer->name,
            // Markets that require a mobile operator (Ghana/Uganda/Zambia).
            'network' => $market['network'] !== [] ? $this->resolveNetwork($country, $msisdn, $request) : null,
            // Francophone markets identify the country explicitly.
            'country' => $market['franco'] ? $country : null,
        ], fn ($value) => $value !== null);

        $response = Http::withToken($this->secretKey)
            ->acceptJson()
            ->post(self::BASE_URL.'/charges?type='.$market['type'], $payload);

        // Flutterwave acknowledges an initiated charge with status "success"; the
        // collection itself starts pending until the payer approves the prompt.
        $accepted = $response->successful() && $response->json('status') === 'success';
        $providerStatus = (string) $response->json('data.status');

        if (! $accepted || strcasecmp($providerStatus, 'failed') === 0) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json() ?: ['body' => $response->body()],
            ]);
            $message = $response->json('message') ?? 'Flutterwave payment request failed';
            $statusCode = $response->status() >= 400 ? $response->status() : 422;

            return ApiResponse::error($message, $statusCode);
        }

        $transaction->update([
            // Flutterwave's transaction id, used to verify the charge.
            'provider_transaction_id' => (string) ($response->json('data.id') ?? $reference),
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
     * Re-check a transaction with Flutterwave and persist its latest status.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $response = Http::withToken($this->secretKey)
            ->acceptJson()
            ->get(self::BASE_URL.'/transactions/'.$transaction->provider_transaction_id.'/verify');

        if (! $response->successful() || $response->json('status') !== 'success') {
            $message = $response->json('message') ?? 'Unable to verify the transaction with Flutterwave';
            $statusCode = $response->status() >= 400 ? $response->status() : 502;

            return ApiResponse::error($message, $statusCode);
        }

        $providerStatus = (string) $response->json('data.status');
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
     * The mobile operator network for a market that requires one — from the
     * caller's `network` if supplied, otherwise inferred from the number's
     * dialling prefix, falling back to the market's first supported network.
     */
    private function resolveNetwork(string $country, string $msisdn, Request $request): string
    {
        $networks = self::MARKETS[$country]['network'];

        $given = strtoupper(trim((string) $request->input('network')));
        if ($given !== '' && in_array($given, $networks, true)) {
            return $given;
        }

        $callingCode = (string) Market::callingCode($country);
        $local = str_starts_with($msisdn, $callingCode) ? substr($msisdn, strlen($callingCode)) : $msisdn;
        $prefix = '0'.substr($local, 0, 2);

        return self::NETWORK_PREFIXES[$country][$prefix] ?? $networks[0];
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
     * Normalise a phone number to the international MSISDN Flutterwave expects
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
     * Map a Flutterwave transaction status onto our internal TransactionStatus.
     * Flutterwave statuses: successful | failed | pending.
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
