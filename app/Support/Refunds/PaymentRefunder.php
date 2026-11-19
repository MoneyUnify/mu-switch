<?php

namespace App\Support\Refunds;

use App\Contracts\PaymentProviderInterface;
use App\Contracts\RefundsPayments;
use App\Enums\TransactionStatus;
use App\Exceptions\RefundException;
use App\Models\CrossBorderTransfer;
use App\Models\Transaction;
use App\Support\Currency;
use App\Support\Fees\FeeCalculator;
use App\Support\ProviderCallContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Refunds a settled payment to its payer, in full or in part, through the
 * provider that collected it (PayPal today).
 *
 * Each refund is its own debit transaction (`refund_of_id`), so the ledger
 * shows the money leaving the account, and the payment can never be refunded
 * for more than was paid.
 */
class PaymentRefunder
{
    /**
     * Refund a payment. Without an amount, refunds whatever is still refundable.
     *
     * @throws RefundException
     */
    public function refund(Transaction $payment, BigDecimal|string|null $amount = null, ?string $reason = null, ?string $idempotencyKey = null): Transaction
    {
        $provider = $payment->paymentProvider;
        $driver = $provider && class_exists($provider->class) ? app($provider->class) : null;

        if ($payment->direction !== 'credit' || $payment->refund_of_id !== null) {
            throw new RefundException('Only payments can be refunded.');
        }

        if ($payment->status !== TransactionStatus::SUCCESS) {
            throw new RefundException('Only a completed payment can be refunded.');
        }

        if (! $driver instanceof RefundsPayments || ! $driver instanceof PaymentProviderInterface) {
            throw new RefundException(($provider->name ?? 'This provider').' does not support refunds.');
        }

        $this->guardCrossBorder($payment);

        // One refund at a time per payment, so two requests can't both pass the balance check.
        $lock = Cache::lock("refund:payment:{$payment->id}", 60);

        if (! $lock->get()) {
            throw new RefundException('Another refund for this payment is in progress. Try again in a moment.', 409);
        }

        try {
            if ($idempotencyKey && ($existing = $this->byKey($payment, $idempotencyKey))) {
                return $existing;
            }

            $refundable = $this->refundable($payment);
            $value = $amount === null ? $refundable : $this->money($amount, $payment->currency);

            if (! $value->isPositive()) {
                throw new RefundException($refundable->isZero() ? 'This payment has already been refunded in full.' : 'The refund amount must be more than zero.');
            }

            if ($value->isGreaterThan($refundable)) {
                throw new RefundException("At most {$refundable} {$payment->currency} can still be refunded.");
            }

            $refund = $this->newRefund($payment, $value, $reason, $idempotencyKey);
        } finally {
            $lock->release();
        }

        ProviderCallContext::set($provider);

        try {
            if (! $driver->setProvider($provider)) {
                $driver->refundPayment($payment, $refund, $reason);
            } else {
                $refund->update(['status' => TransactionStatus::FAILED, 'provider_response' => ['error' => 'The provider is not configured']]);
            }
        } catch (Throwable $e) {
            // The provider may have refunded: keep it pending so it's checked, never retried blind.
            report($e);
            $refund->update(['status' => TransactionStatus::PENDING]);
        } finally {
            ProviderCallContext::clear();
        }

        return $refund->refresh();
    }

    /**
     * What can still be refunded: the payment less refunds that succeeded or
     * may still succeed.
     */
    public function refundable(Transaction $payment): BigDecimal
    {
        $paid = $this->money((string) $payment->amount, $payment->currency);
        $refunded = $this->refunded($payment, includePending: true);

        $left = $paid->minus($refunded);

        return $left->isNegative() ? BigDecimal::zero()->toScale($paid->getScale()) : $left;
    }

    /**
     * Refunds recorded for the payment.
     */
    public function refunded(Transaction $payment, bool $includePending = false): BigDecimal
    {
        $statuses = $includePending ? [TransactionStatus::SUCCESS->value, TransactionStatus::PENDING->value] : [TransactionStatus::SUCCESS->value];

        $minor = Transaction::query()
            ->where('refund_of_id', $payment->id)
            ->whereIn('status', $statuses)
            ->get(['amount', 'currency'])
            ->sum(fn (Transaction $refund): int => Currency::toMinorUnits((string) $refund->amount, $payment->currency));

        return Currency::fromMinorUnits($minor, $payment->currency);
    }

    /**
     * A payment that funded a cross-border transfer is refunded by the transfer
     * itself if delivery fails. Refunding it by hand after the recipient was
     * paid would pay out twice, so it's refused.
     *
     * @throws RefundException
     */
    private function guardCrossBorder(Transaction $payment): void
    {
        $transfer = CrossBorderTransfer::query()->where('funding_transaction_id', $payment->id)->first();

        if ($transfer) {
            throw new RefundException("This payment funds cross-border transfer {$transfer->reference} ({$transfer->status->label()}). It's refunded automatically if delivery fails, so it can't be refunded by hand.");
        }
    }

    private function newRefund(Transaction $payment, BigDecimal $amount, ?string $reason, ?string $idempotencyKey): Transaction
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $idempotencyKey): Transaction {
            $id = (string) Str::uuid();

            $refund = new Transaction([
                'transaction_id' => $id,
                'provider_transaction_id' => $id,
                'customer_id' => $payment->customer_id,
                'amount' => (string) $amount,
                'currency' => $payment->currency,
                'country' => $payment->country,
                'status' => TransactionStatus::PENDING,
                'direction' => 'debit',
                'refund_of_id' => $payment->id,
                'idempotency_key' => $idempotencyKey,
                'provider_response' => $reason ? ['reason' => $reason] : null,
            ]);
            $refund->paymentProvider()->associate($payment->paymentProvider);
            $refund->save();

            return $refund;
        });
    }

    private function byKey(Transaction $payment, string $key): ?Transaction
    {
        return Transaction::query()->where('refund_of_id', $payment->id)->where('idempotency_key', $key)->first();
    }

    /**
     * An amount in the currency's precision.
     *
     * @throws RefundException
     */
    private function money(BigDecimal|string $amount, string $currency): BigDecimal
    {
        $value = $amount instanceof BigDecimal ? $amount : FeeCalculator::money($amount);
        $decimals = Currency::decimals($currency);

        if ($value->strippedOfTrailingZeros()->getScale() > $decimals) {
            throw new RefundException("{$currency} amounts can have at most {$decimals} decimal places.");
        }

        return $value->toScale($decimals, RoundingMode::Unnecessary);
    }
}
