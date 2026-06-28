<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderLog extends Model
{
    protected $fillable = [
        'payment_provider_id',
        'user_id',
        'request_id',
        'method',
        'url',
        'host',
        'request_headers',
        'request_body',
        'response_status',
        'response_body',
        'duration_ms',
        'failed',
        'error_message',
    ];

    /**
     * The provider this outgoing call was made to.
     */
    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    /**
     * The account whose payment triggered this call, if any.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'response_status' => 'integer',
            'duration_ms' => 'integer',
            'failed' => 'boolean',
        ];
    }
}
