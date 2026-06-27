<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Model;

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
        'is_fx',
        'fx_rate',
        'callback_url',
        'callback_notified_at',
    ];

    public function paymentProvider()
    {
        return $this->belongsTo(PaymentProvider::class);
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
            'callback_notified_at' => 'datetime',
        ];
    }
}
