<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Observers\TransactionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(TransactionObserver::class)]
class Transaction extends Model
{
    protected $fillable = [
        'transaction_id',
        'payment_provider_id',
        'provider_transaction_id',
        'customer_id',
        'amount',
        'currency',
        'country',
        'status',
        'provider_response',
        'direction',
        'refund_of_id',
        'is_fx',
        'fx_rate',
        'collection_fee',
        'settlement_fee',
        'net_amount',
        'fee_estimated',
        'callback_url',
        'callback_notified_at',
        'return_url',
        'idempotency_key',
    ];

    public function paymentProvider()
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    /**
     * Refunds of this payment (debits with refund_of_id pointing here).
     */
    public function refunds()
    {
        return $this->hasMany(Transaction::class, 'refund_of_id')->latest('id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Whether the transaction has reached a final (settled) state.
     */
    public function isFinal(): bool
    {
        return in_array($this->status, [TransactionStatus::SUCCESS, TransactionStatus::FAILED], true);
    }

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
            'is_fx' => 'boolean',
            'fx_rate' => 'decimal:8',
            'provider_response' => 'array',
            'collection_fee' => 'decimal:4',
            'settlement_fee' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'fee_estimated' => 'boolean',
            'callback_notified_at' => 'datetime',
        ];
    }
}
