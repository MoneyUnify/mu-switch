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
use App\Support\KazangException;
use App\Support\Market;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Kazang (ContentReady) driver — mobile-money request-to-pay in Zambia.
 *
 * Kazang's ContentReady API is a session-based REST/JSON service: the client
 * logs in with `authClient` for a `session_uuid` (~4h) and posts every method
 * to `https://<host>/apimanager/api_rest/v1/<method>`. Amounts are sent in the
 * currency's smallest unit (ngwee — K5.00 => "500") and every response carries
 * a `response_code` where 0 is success.
 *
 * For collections it exposes wallet-debit ("request to pay") flows that push a
 * prompt to the payer's handset and credit the API user's Kazang wallet once the
 * payer approves. This driver implements the two operators whose flow is keyed
 * on a stable, session-independent reference — making the initiate/poll-verify
 * model safe across sessions:
 *   • MTN MoMo  — mtnDebit → (payer approves) → mtnDebitApproval → …Confirm,
 *                 keyed on `supplier_transaction_id`.
 *   • Airtel    — airtelPayPayment → …Confirm → (payer approves) →
 *                 airtelPayQuery → …Confirm, keyed on `airtel_reference`
 *                 (explicitly retry-able until Airtel completes it).
 *
 * requestPayment initiates the debit and leaves the transaction pending while
 * the payer approves; verifyPayment runs the settle/query flow. To avoid ever
 * mis-reporting money movement, verify only promotes to SUCCESS on an explicit
 * success (response_code 0) and otherwise leaves the transaction pending.
 *
 * The operator's `product_id`, the production host, and the API credentials are
 * account-specific and supplied via config (obtained from Kazang) — never
 * guessed.
 */
class KazangController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'Kazang';

    /**
     * Kazang's request-to-pay flows this driver implements are Zambia wallets.
     */
    public const SUPPORTED_COUNTRIES = ['ZM'];

    public const DEFAULT_COUNTRIES = 'ZM';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/kazang.png';

    /**
     * The credential/config fields the dashboard collects for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'username', 'label' => 'API Username', 'type' => 'text'],
        ['key' => 'password', 'label' => 'API Password', 'type' => 'password'],
        ['key' => 'channel', 'label' => 'API Channel', 'type' => 'text'],
        ['key' => 'host', 'label' => 'API Host', 'type' => 'text'],
        ['key' => 'mtn_product_id', 'label' => 'MTN MoMo Product ID', 'type' => 'text'],
        ['key' => 'airtel_product_id', 'label' => 'Airtel Pay Product ID', 'type' => 'text'],
    ];

    /**
     * Local two-digit prefix (after the country code) => Zambian operator.
     * MTN: 096x/076x — Airtel: 097x/077x.
     *
     * @var array<string, string>
     */
    private const OPERATOR_PREFIXES = [
        '96' => 'mtn',
        '76' => 'mtn',
        '97' => 'airtel',
        '77' => 'airtel',
    ];

    /**
     * response_code values that mean the current session is gone and the
     * client must authenticate again (base doc §2.4 / Appendix C).
     *
     * @var list<string>
     */
    private const SESSION_EXPIRED_CODES = ['7', '8', '9', '22'];

    private string $username;

    private string $password;

    private string $channel;

    private string $host;

    /**
     * Map of operator key => Kazang product_id.
     *
     * @var array<string, string>
     */
    private array $productIds = [];

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        foreach (['username', 'password', 'channel', 'host'] as $field) {
            if (empty($config[$field] ?? null)) {
                return ApiResponse::error('Username, Password, Channel and Host are required for the Kazang provider', 400);
            }
        }

        $this->username = $config['username'];
        $this->password = $config['password'];
        $this->channel = $config['channel'];
        // Accept a bare host or a full URL; normalise to a bare host.
        $this->host = preg_replace('#^https?://#', '', rtrim((string) $config['host'], '/'));
        $this->productIds = [
            'mtn' => (string) ($config['mtn_product_id'] ?? ''),
            'airtel' => (string) ($config['airtel_product_id'] ?? ''),
        ];
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate a wallet debit (request to pay) — pushing the approval prompt to
     * the payer's handset — and leave the transaction pending.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        if ($country !== 'ZM') {
            return ApiResponse::error("Kazang only supports request to pay in Zambia (ZM), not {$country}", 422);
        }

        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);
        $operator = $this->resolveOperator($msisdn, $request);
        if (! $operator) {
            return ApiResponse::error('Kazang request to pay supports MTN and Airtel numbers only', 422);
        }

        $productId = trim((string) ($this->productIds[$operator] ?? ''));
        if ($productId === '') {
            return ApiResponse::error("No Kazang product ID is configured for {$operator}", 422);
        }

        $amountMinor = $this->toMinorUnits($request['amount']);
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

        try {
            [$operatorReference, $initiate] = $operator === 'mtn'
                ? $this->initiateMtn($productId, $amountMinor, $msisdn, $reference)
                : $this->initiateAirtel($productId, $amountMinor, $msisdn, $reference);
        } catch (KazangException $exception) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => ['error' => $exception->payload],
            ]);

            return ApiResponse::error($exception->getMessage(), 422);
        }

        $transaction->update([
            'provider_transaction_id' => $operatorReference,
            'status' => TransactionStatus::PENDING,
            'provider_response' => [
                'operator' => $operator,
                'reference' => $operatorReference,
                'msisdn' => $msisdn,
                'amount_minor' => $amountMinor,
                'initiate' => $initiate,
            ],
        ]);

        return ApiResponse::success('Payment request initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
        ]);
    }

    /**
     * Run the operator's settle/query flow and promote the transaction to
     * success once the payer has approved the prompt.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $meta = is_array($transaction->provider_response) ? $transaction->provider_response : [];
        $operator = $meta['operator'] ?? $this->resolveOperator((string) ($meta['msisdn'] ?? ''), null);
        $reference = $meta['reference'] ?? $transaction->provider_transaction_id;
        $msisdn = $meta['msisdn'] ?? '';
        $amountMinor = $meta['amount_minor'] ?? $this->toMinorUnits((string) $transaction->amount);
        $productId = trim((string) ($this->productIds[$operator] ?? ''));

        if (! $operator || $productId === '' || ! $reference || ! $msisdn) {
            return ApiResponse::error('This transaction cannot be verified with Kazang (missing routing data)', 422);
        }

        try {
            $settled = $operator === 'mtn'
                ? $this->settleMtn($productId, (int) $amountMinor, (string) $msisdn, (string) $reference)
                : $this->settleAirtel($productId, (int) $amountMinor, (string) $msisdn, (string) $reference);
        } catch (KazangException $exception) {
            // A settle/query error is treated as "still pending" so a payment
            // that may yet be approved is never wrongly marked failed.
            $settled = null;
        }

        // Only an explicit success moves money; anything else stays pending.
        $status = $settled === true ? TransactionStatus::SUCCESS : TransactionStatus::PENDING;

        $transaction->update([
            'status' => $status,
            'provider_response' => array_merge($meta, ['verify' => $settled]),
        ]);

        return ApiResponse::status($status->value, self::statusMessage($status), [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $status->value,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
        ]);
    }

    /**
     * MTN MoMo: create the pending debit (pushes the MTN prompt to the payer).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function initiateMtn(string $productId, int $amountMinor, string $msisdn, string $reference): array
    {
        $response = $this->call('mtnDebit', [
            'product_id' => (int) $productId,
            'client_transaction_reference' => $reference,
            'amount' => (string) $amountMinor,
            'wallet_msisdn' => $msisdn,
        ]);

        $this->assertOk($response, 'MTN debit request failed');
        $supplierReference = (string) ($response['supplier_transaction_id'] ?? '');
        if ($supplierReference === '') {
            throw new KazangException('MTN did not return a payment reference', $response);
        }

        return [$supplierReference, $response];
    }

    /**
     * Airtel: create + confirm the payment request (pushes the Airtel prompt).
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function initiateAirtel(string $productId, int $amountMinor, string $msisdn, string $reference): array
    {
        $payment = $this->call('airtelPayPayment', [
            'product_id' => (int) $productId,
            'client_transaction_reference' => $reference,
            'amount' => (string) $amountMinor,
            'wallet_msisdn' => $msisdn,
        ]);
        $this->assertOk($payment, 'Airtel payment request failed');

        $confirm = $this->call('airtelPayPaymentConfirm', [
            'product_id' => (int) $productId,
            'confirmation_number' => (string) ($payment['confirmation_number'] ?? ''),
        ]);
        $this->assertOk($confirm, 'Airtel payment request failed');

        $airtelReference = (string) ($confirm['airtel_reference'] ?? '');
        if ($airtelReference === '') {
            throw new KazangException('Airtel did not return a payment reference', $confirm);
        }

        return [$airtelReference, ['payment' => $payment, 'confirm' => $confirm]];
    }

    /**
     * MTN MoMo settle: approve + confirm. Returns true only on a paid debit.
     */
    private function settleMtn(string $productId, int $amountMinor, string $msisdn, string $reference): bool
    {
        $approval = $this->call('mtnDebitApproval', [
            'product_id' => (int) $productId,
            'amount' => (string) $amountMinor,
            'wallet_msisdn' => $msisdn,
            'supplier_transaction_id' => $reference,
        ]);
        if (! $this->isOk($approval)) {
            return false;
        }

        $confirm = $this->call('mtnDebitApprovalConfirm', [
            'product_id' => (int) $productId,
            'confirmation_number' => (string) ($approval['confirmation_number'] ?? ''),
        ]);

        return $this->isOk($confirm);
    }

    /**
     * Airtel settle: query + confirm. Returns true only on a completed payment.
     */
    private function settleAirtel(string $productId, int $amountMinor, string $msisdn, string $reference): bool
    {
        $query = $this->call('airtelPayQuery', [
            'product_id' => (int) $productId,
            'amount' => (string) $amountMinor,
            'wallet_msisdn' => $msisdn,
            'airtel_reference' => $reference,
        ]);
        if (! $this->isOk($query)) {
            return false;
        }

        $confirm = $this->call('airtelPayQueryConfirm', [
            'product_id' => (int) $productId,
            'confirmation_number' => (string) ($query['confirmation_number'] ?? ''),
        ]);

        return $this->isOk($confirm);
    }

    /**
     * POST a ContentReady method with the active session, transparently
     * re-authenticating and retrying once if the session has expired.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, array $payload): array
    {
        $response = $this->post($method, array_merge(['session_uuid' => $this->session()], $payload));

        if (in_array((string) ($response['response_code'] ?? ''), self::SESSION_EXPIRED_CODES, true)) {
            $response = $this->post($method, array_merge(['session_uuid' => $this->session(true)], $payload));
        }

        return $response;
    }

    /**
     * The current Kazang session UUID, logging in (and caching it) when needed.
     */
    private function session(bool $fresh = false): string
    {
        $key = 'kazang_session_'.$this->provider?->id;

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(210), function (): string {
            $response = $this->post('authClient', [
                'username' => $this->username,
                'password' => $this->password,
                'channel' => $this->channel,
                'time_stamp' => now()->format('Y-m-d H:i:s'),
            ]);

            $session = (string) ($response['session_uuid'] ?? '');
            if (! $this->isOk($response) || $session === '') {
                throw new KazangException($response['response_message'] ?? 'Kazang authentication failed', $response);
            }

            return $session;
        });
    }

    /**
     * POST a raw JSON method to the ContentReady REST endpoint.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $method, array $payload): array
    {
        $response = Http::acceptJson()->asJson()
            ->post('https://'.$this->host.'/apimanager/api_rest/v1/'.$method, $payload);

        return $this->decode($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : ['response_code' => '1', 'response_message' => $response->body()];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function isOk(array $response): bool
    {
        return (string) ($response['response_code'] ?? '') === '0';
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function assertOk(array $response, string $fallback): void
    {
        if (! $this->isOk($response)) {
            throw new KazangException((string) ($response['response_message'] ?? $fallback), $response);
        }
    }

    /**
     * The operator to route through — the caller's explicit `operator` if given
     * (mtn/airtel), otherwise inferred from the number's dialling prefix.
     */
    private function resolveOperator(string $msisdn, ?Request $request): ?string
    {
        $given = strtolower(trim((string) ($request?->input('operator') ?? '')));
        if (in_array($given, ['mtn', 'airtel'], true)) {
            return $given;
        }

        $callingCode = (string) Market::callingCode('ZM');
        $local = str_starts_with($msisdn, $callingCode) ? substr($msisdn, strlen($callingCode)) : $msisdn;

        return self::OPERATOR_PREFIXES[substr($local, 0, 2)] ?? null;
    }

    /**
     * Convert a major-unit amount (e.g. "5.00") to Kazang's minor units ("500").
     */
    private function toMinorUnits(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

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
     * Normalise a phone number to the international MSISDN (e.g. "260966...").
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
