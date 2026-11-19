<?php

namespace App\Models;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Support\Currency;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One side of a money event, in whole minor units of its currency.
 */
class LedgerEntry extends Model
{
    protected $fillable = [
        'user_id', 'payment_provider_id', 'account', 'type', 'currency', 'amount_minor',
        'estimated', 'source_type', 'source_id', 'occurred_at', 'meta',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    /**
     * The transaction (or, later, transfer) that caused this entry.
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The exact signed amount.
     */
    public function amount(): BigDecimal
    {
        return Currency::fromMinorUnits($this->amount_minor, $this->currency);
    }

    protected function casts(): array
    {
        return [
            'account' => LedgerAccount::class,
            'type' => LedgerEntryType::class,
            'amount_minor' => 'integer',
            'estimated' => 'boolean',
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
