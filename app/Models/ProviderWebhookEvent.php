<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A webhook delivery received from a provider, stored for idempotency and audit.
 */
class ProviderWebhookEvent extends Model
{
    protected $fillable = ['payment_provider_id', 'event_id', 'event_type', 'payload', 'processed_at'];

    public function paymentProvider()
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
