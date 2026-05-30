<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Enums\TransactionStatus;

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
        'fx_rate'
    ];


    public function paymentProvider()
    {
        return $this->belongsTo(PaymentProvider::class);
    }
    protected function casts(): array {
        return [
            'status' => TransactionStatus::class,
        ];
    }
}
