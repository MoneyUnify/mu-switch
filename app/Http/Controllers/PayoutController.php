<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Contracts\PayoutProviderInterface;
use App\Exceptions\NoEligibleProviderException;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Currency;
use App\Support\Fees\FeeCalculator;
use App\Support\Market;
use App\Support\Payouts\PayoutDispatcher;
use App\Support\ProviderRouter;
use App\Support\TransactionVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Sends money out (payouts) through the account's payout-capable providers.
 *
 * Fallback is only ever taken when a provider definitely refused the payout.
 * If a provider's answer is unknown, the switch stops: trying another
 * provider could pay the recipient twice. That payout must be verified.
 */
class PayoutController extends Controller
{
    public function __construct(private readonly PayoutDispatcher $dispatcher) {}

    /**
     * How long an Idempotency-Key is locked while its payout is being sent.
     */
    private const LOCK_SECONDS = 300;

    /**
     * Send a payout, falling back across providers that refuse it.
     */
    public function requestPayout(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'account_number' => 'required|string|max:50',
            'country' => ['required', 'string', 'size:2', Rule::in(Market::codes())],
            'currency' => 'nullable|string|size:3|alpha',
            // Optional: the recipient's network, when the provider can't detect it.
            'network' => 'nullable|string|max:50',
            // Optional: shown to the recipient where the provider supports it.
            'narration' => 'nullable|string|max:100',
            'callback_url' => 'nullable|url',
        ]);

        $user = $request->user();
        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey !== null && (strlen($idempotencyKey) < 1 || strlen($idempotencyKey) > 255)) {
            return ApiResponse::error('The Idempotency-Key header must be 1 to 255 characters', 422);
        }

        if ($idempotencyKey && ($existing = $this->findByIdempotencyKey($user, $idempotencyKey))) {
            return $this->replay($existing);
        }

        $country = strtoupper($request['country']);
        $currency = Currency::resolve($request->input('currency'), $country);
        $amount = FeeCalculator::money((string) $request->input('amount'));
        $decimals = Currency::decimals($currency);

        if ($amount->strippedOfTrailingZeros()->getScale() > $decimals) {
            return ApiResponse::error("{$currency} amounts can have at most {$decimals} decimal places", 422);
        }

        try {
            $providers = ProviderRouter::route($user, $country, $currency, $amount->toFloat(), PayoutProviderInterface::class);
        } catch (NoEligibleProviderException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        // Two identical requests racing each other must not both pay.
        $lock = $idempotencyKey ? Cache::lock("payout:{$user->id}:".sha1($idempotencyKey), self::LOCK_SECONDS) : null;

        if ($lock && ! $lock->get()) {
            return ApiResponse::error('A payout with this Idempotency-Key is already being processed', 409);
        }

        try {
            // Re-check under the lock: a request that just finished may have used this key.
            if ($idempotencyKey && ($existing = $this->findByIdempotencyKey($user, $idempotencyKey))) {
                return $this->replay($existing);
            }

            $result = $this->dispatcher->send($providers, [
                'account_number' => (string) $request['account_number'],
                'network' => $request->input('network'),
                'narration' => $request->input('narration'),
            ], (string) $request['amount'], $currency, $country, $request->input('callback_url'));

            if ($idempotencyKey && ! $result->refused()) {
                $result->transaction?->update(['idempotency_key' => $idempotencyKey]);
            }

            return $result->response;
        } finally {
            $lock?->release();
        }
    }

    /**
     * Re-check a payout through the provider that sent it.
     */
    public function verifyPayout(Request $request, TransactionVerifier $verifier): JsonResponse
    {
        $validated = $request->validate(['transaction_id' => 'required|string']);

        $transaction = Transaction::query()
            ->whereIn('payment_provider_id', $request->user()->paymentProviders()->pluck('id'))
            ->where('direction', 'debit')
            ->where('transaction_id', $validated['transaction_id'])
            ->with('paymentProvider')
            ->first();

        if (! $transaction) {
            return ApiResponse::error('Payout not found', 404);
        }

        return $verifier->verify($transaction);
    }

    /**
     * The payout already sent for this Idempotency-Key, if any.
     */
    private function findByIdempotencyKey(User $user, string $key): ?Transaction
    {
        return Transaction::query()
            ->whereIn('payment_provider_id', $user->paymentProviders()->pluck('id'))
            ->where('direction', 'debit')
            ->where('idempotency_key', $key)
            ->latest('id')
            ->first();
    }

    /**
     * Return the original payout instead of sending it again.
     */
    private function replay(Transaction $transaction): JsonResponse
    {
        return ApiResponse::status($transaction->status->value, 'Payout already requested with this Idempotency-Key', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => $transaction->status->value,
            'replayed' => true,
        ]);
    }
}
