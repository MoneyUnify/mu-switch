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
 * payer approves. Zambia's three major wallets are implemented exactly as
 * documented:
 *   • MTN MoMo  — mtnDebit → (payer approves) → mtnDebitApproval → …Confirm,
 *                 keyed on the stable `supplier_transaction_id`.
 *   • Airtel    — airtelPayPayment → …Confirm → (payer approves) →
 *                 airtelPayQuery → …Confirm, keyed on the stable
 *                 `airtel_reference` (explicitly retry-able until it completes).
 *   • Zamtel    — zamtelMoneyPay → (payer enters PIN on the Zamtel USSD
 *                 interface) → zamtelMoneyPayConfirm, whose success receipt
 *                 completes the payment ("Payment Successful").
 *
 * Because Kazang's other markets (South Africa, Namibia, Botswana) follow the
 * same uniform envelope — one authClient session, one JSON POST per method,
 * an operator-assigned `product_id` — but their pay-direction method names are
 * account-specific, those markets are **config-driven**: ticking one in the
 * dashboard opens a dialog asking for the operator's method names and product
 * id (from your Kazang product list / account manager), and the driver runs
 * the same initiate → confirm → (optional query) pattern with them.
 *
 * To avoid ever mis-reporting money movement, a transaction is promoted to
 * SUCCESS only on an explicit success (response_code 0) of the flow's final
 * step, and otherwise stays pending; only a failed initiation is marked failed.
 */
class KazangController extends Controller implements PaymentProviderInterface
{
    public const PROVIDER_NAME = 'Kazang';

    /**
     * Zambia's wallets are fully documented; South Africa, Namibia and Botswana
     * follow the same ContentReady envelope with account-specific pay methods,
     * collected per market via the dashboard's integration dialog.
     */
    public const SUPPORTED_COUNTRIES = ['ZM', 'ZA', 'NA', 'BW'];

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
        ['key' => 'mtn_product_id', 'label' => 'MTN MoMo Product ID (Zambia)', 'type' => 'text'],
        ['key' => 'airtel_product_id', 'label' => 'Airtel Pay Product ID (Zambia)', 'type' => 'text'],
        ['key' => 'zamtel_product_id', 'label' => 'Zamtel Money Product ID (Zambia)', 'type' => 'text'],
    ];

    /**
     * Markets whose pay methods are account-specific: ticking one of these in
     * the dashboard opens an integration dialog collecting the ContentReady
     * method names + product id for that market's wallet (from your Kazang
     * product list). Stored in config under `market_operators.{COUNTRY}`.
     */
    public const MARKET_EXTRA_FIELDS = [
        'key' => 'market_operators',
        'countries' => ['ZA', 'NA', 'BW'],
        'title' => 'Kazang market integration',
        'description' => 'This market uses the same Kazang session and envelope as Zambia, but its wallet\'s method names and product ID are assigned per account. Enter them from your Kazang product list (productList) or account manager.',
        'fields' => [
            ['key' => 'product_id', 'label' => 'Product ID', 'required' => true, 'placeholder' => 'e.g. 5305 (from productList)'],
            ['key' => 'pay_method', 'label' => 'Payment method', 'required' => true, 'placeholder' => 'e.g. mtcMarisPay'],
            ['key' => 'pay_confirm_method', 'label' => 'Payment confirm method', 'required' => false, 'placeholder' => 'e.g. mtcMarisPayConfirm (optional)'],
            ['key' => 'query_method', 'label' => 'Status query method', 'required' => false, 'placeholder' => 'e.g. mtcMarisPayQuery (optional)'],
            ['key' => 'query_confirm_method', 'label' => 'Status confirm method', 'required' => false, 'placeholder' => 'e.g. mtcMarisPayQueryConfirm (optional)'],
            ['key' => 'msisdn_param', 'label' => 'Phone parameter name', 'required' => false, 'placeholder' => 'wallet_msisdn (default)'],
            ['key' => 'reference_field', 'label' => 'Reference field name', 'required' => false, 'placeholder' => 'transaction_reference_str (default)'],
        ],
    ];

    /**
     * Local two-digit prefix (after the country code) => Zambian operator.
     * MTN: 096x/076x — Airtel: 097x/077x — Zamtel: 095x/075x.
     *
     * @var array<string, string>
     */
    private const OPERATOR_PREFIXES = [
        '96' => 'mtn',
        '76' => 'mtn',
        '97' => 'airtel',
        '77' => 'airtel',
        '95' => 'zamtel',
        '75' => 'zamtel',
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

    /**
     * Config-driven wallet integrations for the non-Zambia markets:
     * country code => {product_id, pay_method, pay_confirm_method?, …}.
     *
     * @var array<string, array<string, string>>
     */
    private array $marketOperators = [];

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
            'zamtel' => (string) ($config['zamtel_product_id'] ?? ''),
        ];
        $this->marketOperators = is_array($config['market_operators'] ?? null) ? $config['market_operators'] : [];
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
        if (! in_array($country, self::SUPPORTED_COUNTRIES, true)) {
            return ApiResponse::error("Kazang does not support request to pay in {$country}", 422);
        }

        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

        if ($country === 'ZM') {
            $operator = $this->resolveOperator($msisdn, $request);
            if (! $operator) {
                return ApiResponse::error('Kazang request to pay supports MTN, Airtel and Zamtel numbers only', 422);
            }

            $productId = trim((string) ($this->productIds[$operator] ?? ''));
            if ($productId === '') {
                return ApiResponse::error("No Kazang product ID is configured for {$operator}", 422);
            }
        } else {
            // Config-driven market: the wallet's methods + product id were
            // collected in the dashboard's market-integration dialog.
            $operator = $country;
            if (! $this->customOperator($country)) {
                return ApiResponse::error("The Kazang {$country} market needs its payment method and product ID configured on the provider", 422);
            }
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
            [$operatorReference, $initiate, $status] = match ($operator) {
                'mtn' => $this->initiateMtn($productId, $amountMinor, $msisdn, $reference),
                'airtel' => $this->initiateAirtel($productId, $amountMinor, $msisdn, $reference),
                'zamtel' => $this->initiateZamtel($productId, $amountMinor, $msisdn, $reference),
                default => $this->initiateCustom($this->customOperator($country), $amountMinor, $msisdn, $reference),
            };
        } catch (KazangException $exception) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => ['error' => $exception->payload],
            ]);

            return ApiResponse::error($exception->getMessage(), 422);
        }

        $transaction->update([
            'provider_transaction_id' => $operatorReference,
            'status' => $status,
            'provider_response' => [
                'operator' => $operator,
                'reference' => $operatorReference,
                'msisdn' => $msisdn,
                'amount_minor' => $amountMinor,
                'initiate' => $initiate,
            ],
        ]);

        return ApiResponse::success(
            $status === TransactionStatus::SUCCESS
                ? 'Payment completed successfully'
                : 'Payment request initiated successfully',
            [
                'transaction_id' => $transaction->transaction_id,
                'reference' => $transaction->provider_transaction_id,
                'status' => $transaction->status->value,
            ],
        );
    }

    /**
     * Run the operator's settle/query flow and promote the transaction to
     * success once the payer has approved the prompt.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        // A transaction that already reached a final state stays there — the
        // settle flows below are one-shot and must never downgrade a success.
        if ($transaction->status !== TransactionStatus::PENDING) {
            return ApiResponse::status($transaction->status->value, self::statusMessage($transaction->status), [
                'transaction_id' => $transaction->transaction_id,
                'reference' => $transaction->provider_transaction_id,
                'status' => $transaction->status->value,
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
            ]);
        }

        $meta = is_array($transaction->provider_response) ? $transaction->provider_response : [];
        $operator = (string) ($meta['operator'] ?? $this->resolveOperator((string) ($meta['msisdn'] ?? ''), null));
        $reference = (string) ($meta['reference'] ?? $transaction->provider_transaction_id);
        $msisdn = (string) ($meta['msisdn'] ?? '');
        $amountMinor = (int) ($meta['amount_minor'] ?? $this->toMinorUnits((string) $transaction->amount));
        $productId = trim((string) ($this->productIds[$operator] ?? ''));

        if ($operator === '' || $reference === '' || (in_array($operator, ['mtn', 'airtel', 'zamtel'], true) && $productId === '')) {
            return ApiResponse::error('This transaction cannot be verified with Kazang (missing routing data)', 422);
        }

        try {
            $settled = match ($operator) {
                'mtn' => $this->settleMtn($productId, $amountMinor, $msisdn, $reference),
                'airtel' => $this->settleAirtel($productId, $amountMinor, $msisdn, $reference),
                'zamtel' => $this->settleZamtel($productId, (string) ($meta['initiate']['confirmation_number'] ?? '')),
                default => $this->settleCustom($this->customOperator($operator), $amountMinor, $msisdn, $reference),
            };
        } catch (KazangException) {
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
     * @return array{0: string, 1: array<string, mixed>, 2: TransactionStatus}
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

        return [$supplierReference, $response, TransactionStatus::PENDING];
    }

    /**
     * Airtel: create + confirm the payment request (pushes the Airtel prompt).
     *
     * @return array{0: string, 1: array<string, mixed>, 2: TransactionStatus}
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

        return [$airtelReference, ['payment' => $payment, 'confirm' => $confirm], TransactionStatus::PENDING];
    }

    /**
     * Zamtel: initiate the payment (Zamtel prompts the payer for their PIN over
     * USSD) and confirm it — a successful confirm is the final receipt.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: TransactionStatus}
     */
    private function initiateZamtel(string $productId, int $amountMinor, string $msisdn, string $reference): array
    {
        $payment = $this->call('zamtelMoneyPay', [
            'product_id' => (int) $productId,
            'client_transaction_reference' => $reference,
            'amount' => (string) $amountMinor,
            'msisdn' => $msisdn,
        ]);
        $this->assertOk($payment, 'Zamtel payment request failed');

        $confirm = $this->call('zamtelMoneyPayConfirm', [
            'product_id' => (int) $productId,
            'confirmation_number' => (string) ($payment['confirmation_number'] ?? ''),
        ]);

        if ($this->isOk($confirm)) {
            $zamtelReference = (string) ($confirm['zamtel_reference'] ?? $confirm['transaction_reference_str'] ?? $reference);

            return [$zamtelReference, ['payment' => $payment, 'confirm' => $confirm], TransactionStatus::SUCCESS];
        }

        // Not confirmed yet (e.g. the payer hasn't entered their PIN) — stay
        // pending; verification retries the confirm with the stored number.
        return [$reference, [
            'payment' => $payment,
            'confirm' => $confirm,
            'confirmation_number' => (string) ($payment['confirmation_number'] ?? ''),
        ], TransactionStatus::PENDING];
    }

    /**
     * Config-driven market (ZA/NA/BW): run the wallet's configured pay method
     * (+ optional confirm) through the same ContentReady envelope. With a query
     * method configured the payment settles on verification; otherwise the
     * confirmed receipt is final.
     *
     * @param  array<string, string>|null  $op
     * @return array{0: string, 1: array<string, mixed>, 2: TransactionStatus}
     */
    private function initiateCustom(?array $op, int $amountMinor, string $msisdn, string $reference): array
    {
        if (! $op) {
            throw new KazangException('This Kazang market is not configured');
        }

        $payment = $this->call($op['pay_method'], [
            'product_id' => (int) $op['product_id'],
            'client_transaction_reference' => $reference,
            'amount' => (string) $amountMinor,
            $this->msisdnParam($op) => $msisdn,
        ]);
        $this->assertOk($payment, 'Kazang payment request failed');

        $final = $payment;
        if ($op['pay_confirm_method'] !== '') {
            $final = $this->call($op['pay_confirm_method'], [
                'product_id' => (int) $op['product_id'],
                'confirmation_number' => (string) ($payment['confirmation_number'] ?? ''),
            ]);
            $this->assertOk($final, 'Kazang payment confirmation failed');
        }

        $referenceField = $this->referenceField($op);
        $operatorReference = (string) ($final[$referenceField] ?? $payment[$referenceField] ?? $final['transaction_reference_str'] ?? $reference);
        $status = $op['query_method'] !== '' ? TransactionStatus::PENDING : TransactionStatus::SUCCESS;

        return [$operatorReference, ['payment' => $payment, 'confirm' => $final], $status];
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
     * Zamtel settle: retry the pay confirm with the stored confirmation number
     * (valid within the session). Returns true only on the success receipt.
     */
    private function settleZamtel(string $productId, string $confirmationNumber): bool
    {
        if ($confirmationNumber === '') {
            return false;
        }

        $confirm = $this->call('zamtelMoneyPayConfirm', [
            'product_id' => (int) $productId,
            'confirmation_number' => $confirmationNumber,
        ]);

        return $this->isOk($confirm);
    }

    /**
     * Config-driven market settle: run the configured query (+ optional
     * confirm). Returns true only when every configured step succeeds.
     *
     * @param  array<string, string>|null  $op
     */
    private function settleCustom(?array $op, int $amountMinor, string $msisdn, string $reference): bool
    {
        if (! $op || $op['query_method'] === '') {
            return false;
        }

        $query = $this->call($op['query_method'], [
            'product_id' => (int) $op['product_id'],
            'amount' => (string) $amountMinor,
            $this->msisdnParam($op) => $msisdn,
            $this->referenceField($op) => $reference,
        ]);
        if (! $this->isOk($query)) {
            return false;
        }

        if ($op['query_confirm_method'] === '') {
            return true;
        }

        $confirm = $this->call($op['query_confirm_method'], [
            'product_id' => (int) $op['product_id'],
            'confirmation_number' => (string) ($query['confirmation_number'] ?? ''),
        ]);

        return $this->isOk($confirm);
    }

    /**
     * The configured wallet integration for a non-Zambia market, normalised
     * with blank defaults, or null when its required fields are missing.
     *
     * @return array<string, string>|null
     */
    private function customOperator(string $country): ?array
    {
        $op = $this->marketOperators[strtoupper($country)] ?? null;
        if (! is_array($op)) {
            return null;
        }

        $op = array_map(fn ($value) => trim((string) $value), $op);
        if (($op['product_id'] ?? '') === '' || ($op['pay_method'] ?? '') === '') {
            return null;
        }

        return $op + ['pay_confirm_method' => '', 'query_method' => '', 'query_confirm_method' => '', 'msisdn_param' => '', 'reference_field' => ''];
    }

    /**
     * @param  array<string, string>  $op
     */
    private function msisdnParam(array $op): string
    {
        return $op['msisdn_param'] !== '' ? $op['msisdn_param'] : 'wallet_msisdn';
    }

    /**
     * @param  array<string, string>  $op
     */
    private function referenceField(array $op): string
    {
        return $op['reference_field'] !== '' ? $op['reference_field'] : 'transaction_reference_str';
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
        if (in_array($given, ['mtn', 'airtel', 'zamtel'], true)) {
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
