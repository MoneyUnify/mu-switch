<?php

namespace App\Http\Controllers\Providers;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\Contracts\PayoutProviderInterface;
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
 * pawaPay driver — mobile-money collections (deposits / push to pay).
 *
 * pawaPay API v2. pawaPay authenticates with a single Bearer API token. A
 * collection is a "deposit": the driver first asks pawaPay's predict-provider
 * endpoint which mobile-money provider (e.g. MTN_MOMO_ZMB) the payer's number
 * belongs to, and gets the number back sanitised, then initiates the deposit,
 * which sends a push prompt straight to the payer. Status is confirmed with the
 * deposit status endpoint. So a merchant only configures their API token and a
 * call needs only country + phone number.
 *
 * Payouts send money to a mobile wallet the same way: the provider is
 * predicted from the number, and the payout id is our transaction id, so a
 * repeated request is ignored by pawaPay rather than paid twice.
 *
 * @see https://docs.pawapay.io/v2/api-reference/deposits/initiate-deposit
 * @see https://docs.pawapay.io/v2/api-reference/payouts/initiate-payout
 * @see https://docs.pawapay.io/v2/docs/upgrade_from_v1
 */
class PawapayController extends Controller implements PaymentProviderInterface, PayoutProviderInterface
{
    public const PROVIDER_NAME = 'pawaPay';

    /**
     * pawaPay's official 20 markets (per pawapay.io/markets). Enable only the
     * markets your pawaPay account is contracted for.
     */
    public const SUPPORTED_COUNTRIES = [
        'BJ', 'BF', 'CM', 'CG', 'CD', 'CI', 'ET', 'GA', 'GH', 'KE',
        'LS', 'MW', 'MZ', 'NG', 'RW', 'SN', 'SL', 'TZ', 'UG', 'ZM',
    ];

    public const DEFAULT_COUNTRIES = 'ZM';

    /**
     * Default logo shown for this driver (editable in the provider dialog).
     */
    public const DEFAULT_LOGO = '/pawapay.png';

    /**
     * The credential fields the dashboard should collect for this driver.
     */
    public const CONFIG_FIELDS = [
        ['key' => 'api_token', 'label' => 'API Token', 'type' => 'password'],
        [
            'key' => 'environment',
            'label' => 'Environment',
            'type' => 'text',
            'options' => ['live', 'sandbox'],
            'rules' => 'string|in:live,sandbox',
            'required' => false,
            'help' => 'Use sandbox with a pawaPay sandbox API token for testing.',
        ],
    ];

    /**
     * pawaPay hosts per environment. Providers saved before this setting existed are live.
     *
     * @var array<string, string>
     */
    private const BASE_URLS = [
        'live' => 'https://api.pawapay.io',
        'sandbox' => 'https://api.sandbox.pawapay.io',
    ];

    /**
     * Initiation statuses meaning pawaPay has taken the deposit or payout on.
     * A duplicate is one pawaPay already accepted under the same id.
     *
     * @var list<string>
     */
    private const ACCEPTED = ['ACCEPTED', 'DUPLICATE_IGNORED'];

    /** How long an unanswered payout request waits before NOT_FOUND counts as never received. */
    private const NOT_FOUND_GRACE_MINUTES = 2;

    private string $apiToken;

    private string $baseUrl = self::BASE_URLS['live'];

    public ?PaymentProvider $provider = null;

    /**
     * Inject the configured provider's credentials into the driver.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

        $apiToken = self::cleanToken($config['api_token'] ?? null);

        if (! $apiToken) {
            return ApiResponse::error('API Token is required for the pawaPay provider', 400);
        }

        $this->apiToken = $apiToken;
        $this->baseUrl = self::BASE_URLS[$config['environment'] ?? 'live'] ?? self::BASE_URLS['live'];
        $this->provider = $provider;

        return null;
    }

    /**
     * Initiate a deposit (push to pay) with pawaPay.
     */
    public function requestPayment(Request $request): JsonResponse
    {
        $country = strtoupper($request['country']);
        $currency = Market::currency($country);
        $msisdn = $this->normaliseMsisdn($request['account_number'], $country);

        // Which mobile-money provider the payer's number belongs to: supplied
        // by the caller, else predicted by pawaPay from the number.
        [$provider, $msisdn, $rejected] = $this->provider(trim((string) ($request->input('provider') ?: $request->input('correspondent'))), $msisdn);
        if ($rejected) {
            return ApiResponse::error($rejected, 502);
        }
        if (! $provider) {
            return ApiResponse::error("Could not determine the mobile-money provider for {$msisdn}", 422);
        }

        $customer = $this->resolveCustomer($msisdn, $country);

        // pawaPay reconciles by depositId (a UUID), which is our transaction_id.
        $depositId = (string) Str::uuid();

        $transaction = new Transaction([
            'transaction_id' => $depositId,
            'customer_id' => $customer->id,
            'amount' => $request['amount'],
            'currency' => $currency,
            'country' => $country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'credit',
            'provider_transaction_id' => $depositId,
            'callback_url' => $request->input('callback_url'),
        ]);
        $transaction->paymentProvider()->associate($this->provider);
        $transaction->save();

        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->post($this->baseUrl.'/v2/deposits', [
                'depositId' => $depositId,
                'payer' => ['type' => 'MMO', 'accountDetails' => ['phoneNumber' => $msisdn, 'provider' => $provider]],
                'amount' => $this->formatAmount($request['amount']),
                'currency' => $currency,
                'customerMessage' => $this->customerMessage($request->input('narration')),
            ]);

        // pawaPay acknowledges an accepted deposit with status "ACCEPTED" (the
        // push is now on its way to the payer). Anything else is a failure.
        $status = strtoupper((string) $response->json('status'));
        $accepted = $response->successful() && in_array($status, self::ACCEPTED, true);

        if (! $accepted) {
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $response->json() ?: ['body' => $response->body()],
            ]);
            $message = self::failureMessage($response->json()) ?? 'pawaPay payment request failed';
            $statusCode = $response->status() >= 400 ? $response->status() : 422;

            return ApiResponse::error($message, $statusCode);
        }

        $transaction->update([
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
     * Re-check a deposit with pawaPay and persist its latest status.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse
    {
        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->get($this->baseUrl.'/v2/deposits/'.$transaction->transaction_id);

        if (! $response->successful()) {
            $message = self::failureMessage($response->json()) ?? 'Unable to verify the transaction with pawaPay';
            $statusCode = $response->status() >= 400 ? $response->status() : 502;

            return ApiResponse::error($message, $statusCode);
        }

        // FOUND, with the deposit under `data`; NOT_FOUND leaves it pending, as before.
        $deposit = $response->json('status') === 'FOUND' ? (array) $response->json('data', []) : [];
        $providerStatus = (string) ($deposit['status'] ?? '');
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
     * Send the payout to the recipient's mobile wallet.
     */
    public function requestPayout(Transaction $transaction, array $recipient): JsonResponse
    {
        $msisdn = $this->normaliseMsisdn($recipient['account_number'], $transaction->country);
        [$provider, $msisdn, $rejected] = $this->provider(trim((string) ($recipient['network'] ?? '')), $msisdn);

        // Nothing was sent to pawaPay, so the payout can safely fail here.
        if ($rejected) {
            return $this->failPayout($transaction, $rejected, ['phoneNumber' => $msisdn]);
        }

        if (! $provider) {
            return $this->failPayout($transaction, "Could not determine the mobile-money provider for {$msisdn}", ['phoneNumber' => $msisdn]);
        }

        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->post($this->baseUrl.'/v2/payouts', [
                'payoutId' => $transaction->transaction_id,
                'recipient' => ['type' => 'MMO', 'accountDetails' => ['phoneNumber' => $msisdn, 'provider' => $provider]],
                'amount' => $this->formatAmount($transaction->amount),
                'currency' => $transaction->currency,
                'customerMessage' => $this->customerMessage($recipient['narration'] ?? null),
            ]);

        // pawaPay didn't give a definite answer: the payout may exist, so it must be verified.
        if ($response->serverError()) {
            $transaction->update(['provider_response' => ['request' => $response->json() ?: ['body' => $response->body()]]]);

            return ApiResponse::error('pawaPay did not confirm the payout; verify it before retrying', 502);
        }

        $status = strtoupper((string) $response->json('status'));

        if (! $response->successful() || ! in_array($status, self::ACCEPTED, true)) {
            $message = self::failureMessage($response->json()) ?? 'pawaPay rejected the payout';

            return $this->failPayout($transaction, $message, $response->json() ?: ['body' => $response->body()]);
        }

        $transaction->update([
            'status' => TransactionStatus::PENDING,
            'provider_response' => ['request' => $response->json()],
        ]);

        return ApiResponse::success('Payout initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
        ]);
    }

    /**
     * Re-check a payout with pawaPay and persist its latest status.
     */
    public function verifyPayout(Transaction $transaction): JsonResponse
    {
        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->get($this->baseUrl.'/v2/payouts/'.$transaction->transaction_id);

        if (! $response->successful()) {
            return ApiResponse::error(self::failureMessage($response->json()) ?? 'Unable to verify the payout with pawaPay', 502);
        }

        $payout = $response->json('status') === 'FOUND' ? (array) $response->json('data', []) : null;
        $providerStatus = $payout ? strtoupper((string) ($payout['status'] ?? '')) : 'NOT_FOUND';
        $status = $payout ? self::mapStatus($providerStatus) : $this->notFoundStatus($transaction);

        $failure = self::failureMessage($payout);

        $transaction->update([
            'status' => $status,
            // pawaPay's reason, where the transfer timeline and Transfers page look for it.
            'provider_response' => array_filter([...($transaction->provider_response ?? []), 'status' => $response->json(), 'message' => $failure], fn ($value): bool => $value !== null),
        ]);

        return ApiResponse::status($status->value, self::statusMessage($status), [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $status->value,
            'provider_status' => $providerStatus,
            'failure_reason' => $failure,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
        ]);
    }

    /**
     * What NOT_FOUND means for a payout. One pawaPay acknowledged is never
     * treated as lost: it stays pending. One whose request got no answer
     * (a dropped connection or a server error) is only taken as never
     * received once it's old enough that pawaPay would have it by now, so a
     * slow pawaPay can't lead to it being paid out a second time elsewhere.
     */
    private function notFoundStatus(Transaction $transaction): TransactionStatus
    {
        $acknowledged = in_array(strtoupper((string) data_get($transaction->provider_response, 'request.status')), self::ACCEPTED, true);

        if ($acknowledged || $transaction->created_at?->gt(now()->subMinutes(self::NOT_FOUND_GRACE_MINUTES))) {
            return TransactionStatus::PENDING;
        }

        return TransactionStatus::FAILED;
    }

    /**
     * Mark a payout pawaPay definitely refused, so another provider may be tried.
     *
     * @param  array<string, mixed>  $response
     */
    private function failPayout(Transaction $transaction, string $message, array $response): JsonResponse
    {
        $transaction->update([
            'status' => TransactionStatus::FAILED,
            'provider_response' => ['request' => $response, 'message' => $message],
        ]);

        return ApiResponse::error($message, 422);
    }

    /**
     * The mobile-money provider for a number, and the number to send:
     * a given provider is used as is; otherwise pawaPay predicts it and
     * returns the number sanitised.
     *
     * @return array{0: ?string, 1: string}
     */
    /**
     * The mobile-money provider for a number: the caller's, else pawaPay's
     * prediction. The third value explains a refusal that isn't about the
     * number, such as pawaPay rejecting the API token.
     *
     * @return array{0: ?string, 1: string, 2: ?string}
     */
    private function provider(string $given, string $msisdn): array
    {
        if ($given !== '') {
            return [strtoupper($given), $msisdn, null];
        }

        $response = Http::withToken($this->apiToken)
            ->acceptJson()
            ->post($this->baseUrl.'/v2/predict-provider', ['phoneNumber' => $msisdn]);

        if (in_array($response->status(), [401, 403], true)) {
            $environment = str_contains($this->baseUrl, 'sandbox') ? 'sandbox' : 'live';
            $reason = self::failureMessage($response->json()) ?? 'The API token was refused';

            return [null, $msisdn, "pawaPay rejected the API token ({$reason}). Check the token on the Providers page: it must be a {$environment} token for the {$environment} environment."];
        }

        if (! $response->successful() || ! filled($response->json('provider'))) {
            return [null, $msisdn, null];
        }

        return [(string) $response->json('provider'), (string) ($response->json('phoneNumber') ?: $msisdn), null];
    }

    /**
     * The token as pawaPay expects it: no surrounding spaces, line breaks or
     * quotes, which easily come along when it's pasted.
     */
    private static function cleanToken(mixed $token): ?string
    {
        $token = trim(trim((string) $token), "\"'");

        return $token === '' ? null : $token;
    }

    /**
     * pawaPay's reason for a refusal or failure (v2's `failureReason`).
     *
     * @param  array<string, mixed>|null  $body
     */
    private static function failureMessage(?array $body): ?string
    {
        $message = data_get($body, 'failureReason.failureMessage') ?? data_get($body, 'failureReason.failureCode');

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * Format the amount as pawaPay expects — a plain number string with no
     * trailing zeros (up to 3 decimals).
     */
    private function formatAmount(mixed $amount): string
    {
        $formatted = rtrim(rtrim(number_format((float) $amount, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * pawaPay's `customerMessage`: 4 to 22 letters, digits and spaces, shown
     * to the payer or recipient where the provider supports it.
     */
    private function customerMessage(?string $narration): string
    {
        $raw = preg_replace('/[^A-Za-z0-9 ]/', '', (string) $narration) ?? '';
        $message = trim(substr(preg_replace('/ {2,}/', ' ', trim($raw)) ?? '', 0, 22));

        return strlen($message) >= 4 ? $message : 'MoneyUnify';
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
     * Normalise a phone number to the international MSISDN pawaPay expects
     * (country code, no leading zero, no '+', e.g. "260763456789").
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
     * Map a pawaPay deposit status onto our internal TransactionStatus.
     * pawaPay statuses: ACCEPTED | SUBMITTED | COMPLETED | FAILED.
     */
    private static function mapStatus(string $providerStatus): TransactionStatus
    {
        return match (strtoupper($providerStatus)) {
            'COMPLETED' => TransactionStatus::SUCCESS,
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
