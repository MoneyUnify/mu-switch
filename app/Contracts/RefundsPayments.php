<?php

namespace App\Contracts;

use App\Enums\TransactionStatus;
use App\Models\Transaction;

/**
 * A payment driver that can return a settled payment to its payer.
 *
 * The refund is recorded as its own debit transaction (`refund_of_id` points
 * at the payment), so the ledger shows the money leaving the account.
 */
interface RefundsPayments
{
    /**
     * Refund the payment in full. Calling it again for the same refund must
     * not refund twice.
     *
     * @return TransactionStatus success when refunded, pending while the provider processes it, failed when refused
     */
    public function refundPayment(Transaction $payment, Transaction $refund, ?string $note = null): TransactionStatus;

    /**
     * Re-check a pending refund.
     */
    public function verifyRefund(Transaction $refund): TransactionStatus;
}
