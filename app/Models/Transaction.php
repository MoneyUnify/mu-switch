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
        'status',
        'provider_response',
        'direction',
        'is_fx',
        'fx_rate',
    ];

    public function paymentProvider()
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    protected function casts(): array
    {
        return [
            'status' => TransactionStatus::class,
        ];
    }
}
