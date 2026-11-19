<?php

namespace App\Http\Controllers\Providers;

use App\ApiResponse;
use App\Contracts\PayoutProviderInterface;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;

/**
 * Simulated payouts for sandbox demos and tests. Never available in production.
 *
 * The recipient number decides the outcome, so every path can be shown on demand:
 *
 * - ends in the refusal suffix (default 0000): refused, so the switch tries the next provider
 * - ends in 9999: no definite answer, so the payout must be verified
 * - ends in 1111: accepted, then fails on verification
 * - anything else: accepted, then completed on verification
 */
class SandboxPayoutController extends Controller implements PayoutProviderInterface
{
    public const PROVIDER_NAME = 'Simulated Payouts';

    public const SANDBOX_ONLY = true;

    public const SUPPORTED_COUNTRIES = [
        'BJ', 'BW', 'BF', 'CM', 'TD', 'CG', 'CI', 'CD', 'SZ', 'ET', 'GA', 'GH', 'GN', 'GW', 'KE', 'LS',
        'LR', 'MG', 'MW', 'MZ', 'NA', 'NE', 'NG', 'RW', 'SN', 'SC', 'SL', 'ZA', 'SS', 'TZ', 'UG', 'ZM',
    ];

    public const DEFAULT_COUNTRIES = 'ZM';

    public const CONFIG_FIELDS = [
        [
            'key' => 'fail_suffix',
            'label' => 'Refuse numbers ending in',
            'type' => 'text',
            'required' => false,
            'help' => 'Payouts to numbers ending in this are refused (default 0000). Numbers ending in 9999 give no answer, and 1111 fails on verification.',
        ],
    ];

    /**
     * An illustrative 1% payout fee, so fees show up in demos.
     */
    public const FEE_SCHEDULE = [
        'payout' => ['percent' => 1.0, 'flat' => 0],
    ];

    private const DEFAULT_FAIL_SUFFIX = '0000';

    private const UNKNOWN_SUFFIX = '9999';

    private const FAILS_LATER_SUFFIX = '1111';

    /**
     * Suffixes matching pawaPay's sandbox failure test numbers (260763456079 / 260973456089)
     * so test cases produce identical refund outcomes on both hosted and local environments.
     */
    private const PAWAPAY_FAIL_SUFFIXES = ['6079', '6089'];

    /**
     * Suffixes matching pawaPay's sandbox pending test numbers (260763456129 / 260973456129).
     */
    private const PAWAPAY_PENDING_SUFFIXES = ['6129'];

    private string $failSuffix = self::DEFAULT_FAIL_SUFFIX;

    public function setProvider(PaymentProvider $provider): ?JsonResponse
    {
        $suffix = trim((string) ($provider->config['fail_suffix'] ?? ''));
        $this->failSuffix = $suffix !== '' ? $suffix : self::DEFAULT_FAIL_SUFFIX;

        return null;
    }

    /**
     * Accept, refuse or leave the payout undecided, based on the number.
     */
    public function requestPayout(Transaction $transaction, array $recipient): JsonResponse
    {
        $msisdn = preg_replace('/\D/', '', $recipient['account_number']) ?? '';
        $response = ['simulated' => true, 'msisdn' => $msisdn];

        if (str_ends_with($msisdn, $this->failSuffix) || $this->matchesSuffix($msisdn, self::PAWAPAY_FAIL_SUFFIXES)) {
            $transaction->update(['status' => TransactionStatus::FAILED, 'provider_response' => [...$response, 'outcome' => 'refused']]);

            return ApiResponse::error('Simulated refusal: the recipient cannot receive payouts', 422);
        }

        if (str_ends_with($msisdn, self::UNKNOWN_SUFFIX) || $this->matchesSuffix($msisdn, self::PAWAPAY_PENDING_SUFFIXES)) {
            $transaction->update(['provider_response' => [...$response, 'outcome' => 'unknown']]);

            return ApiResponse::error('Simulated timeout: the payout outcome is unknown', 502);
        }

        $transaction->update(['status' => TransactionStatus::PENDING, 'provider_response' => [...$response, 'outcome' => 'accepted']]);

        return ApiResponse::success('Payout initiated successfully', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
        ]);
    }

    /**
     * Complete the payout, unless the number is set to fail later.
     */
    public function verifyPayout(Transaction $transaction): JsonResponse
    {
        $msisdn = (string) ($transaction->provider_response['msisdn'] ?? '');
        $outcome = $transaction->provider_response['outcome'] ?? null;

        $status = match (true) {
            $outcome === 'refused' => TransactionStatus::FAILED,
            str_ends_with($msisdn, self::FAILS_LATER_SUFFIX) => TransactionStatus::FAILED,
            $this->matchesSuffix($msisdn, self::PAWAPAY_FAIL_SUFFIXES) => TransactionStatus::FAILED,
            default => TransactionStatus::SUCCESS,
        };

        $transaction->update(['status' => $status]);

        return ApiResponse::status($status->value, $status === TransactionStatus::SUCCESS ? 'Transaction completed successfully' : 'Transaction failed', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $status->value,
            'provider_status' => $status === TransactionStatus::SUCCESS ? 'COMPLETED' : 'FAILED',
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
        ]);
    }

    /**
     * @param  list<string>  $suffixes
     */
    private function matchesSuffix(string $msisdn, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($msisdn, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
