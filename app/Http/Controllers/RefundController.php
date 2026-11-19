<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Exceptions\RefundException;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Currency;
use App\Support\Refunds\PaymentRefunder;
use App\Support\TransactionVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Refund payments to their payers, in full or in part.
 */
class RefundController extends Controller
{
    public function __construct(private readonly PaymentRefunder $refunder) {}

    /**
     * Refund a completed payment. Without an amount, refunds what's left.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transaction_id' => 'required|string',
            'amount' => 'nullable|numeric|gt:0',
            'reason' => 'nullable|string|max:255',
        ]);

        $key = $request->header('Idempotency-Key');

        if ($key !== null && (strlen($key) < 1 || strlen($key) > 255)) {
            return ApiResponse::error('The Idempotency-Key header must be 1 to 255 characters', 422);
        }

        $payment = $this->payment($request->user(), $validated['transaction_id']);

        if (! $payment) {
            return ApiResponse::error('Payment not found', 404);
        }

        try {
            $refund = $this->refunder->refund($payment, isset($validated['amount']) ? (string) $validated['amount'] : null, $validated['reason'] ?? null, $key);
        } catch (RefundException $e) {
            return ApiResponse::error($e->getMessage(), $e->status);
        }

        return $this->respond($payment, $refund);
    }

    /**
     * Re-check a refund with the provider.
     */
    public function verify(Request $request, TransactionVerifier $verifier): JsonResponse
    {
        $validated = $request->validate(['refund_id' => 'required|string']);

        $refund = Transaction::query()
            ->whereIn('payment_provider_id', $request->user()->paymentProviders()->pluck('id'))
            ->whereNotNull('refund_of_id')
            ->where('transaction_id', $validated['refund_id'])
            ->first();

        if (! $refund) {
            return ApiResponse::error('Refund not found', 404);
        }

        $verifier->verify($refund);

        return $this->respond(Transaction::findOrFail($refund->refund_of_id), $refund->refresh());
    }

    /**
     * A transaction's amount in its currency's precision, e.g. "25.00".
     */
    private function money(Transaction $transaction): string
    {
        return (string) Currency::fromMinorUnits(Currency::toMinorUnits((string) $transaction->amount, $transaction->currency), $transaction->currency);
    }

    private function payment(User $user, string $transactionId): ?Transaction
    {
        return Transaction::query()
            ->whereIn('payment_provider_id', $user->paymentProviders()->pluck('id'))
            ->where('direction', 'credit')
            ->where('transaction_id', $transactionId)
            ->with('paymentProvider')
            ->first();
    }

    private function respond(Transaction $payment, Transaction $refund): JsonResponse
    {
        $message = match ($refund->status->value) {
            'success' => 'Refund completed',
            'failed' => 'Refund failed',
            default => 'Refund is processing',
        };

        return ApiResponse::status($refund->status->value, $message, [
            'refund_id' => $refund->transaction_id,
            'reference' => $refund->provider_transaction_id,
            'transaction_id' => $payment->transaction_id,
            'status' => $refund->status->value,
            'amount' => $this->money($refund),
            'currency' => $refund->currency,
            'reason' => $refund->provider_response['reason'] ?? null,
            'failure' => data_get($refund->provider_response, 'error.message') ?? (is_string($refund->provider_response['error'] ?? null) ? $refund->provider_response['error'] : null),
            'payment' => [
                'amount' => $this->money($payment),
                'refunded' => (string) $this->refunder->refunded($payment),
                'refundable' => (string) $this->refunder->refundable($payment),
            ],
        ], $refund->status->value === 'failed' ? 422 : 200);
    }
}
