<?php

namespace App\Models;

use App\Enums\TransferStatus;
use App\Support\Currency;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money moved from one provider account to another, tracked from request to settlement.
 */
class TreasuryTransfer extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'from_provider_id', 'to_provider_id', 'currency', 'to_currency',
        'amount_minor', 'fee_minor', 'received_minor', 'target_amount_minor', 'fx_rate', 'fx_quote_id',
        'status', 'method', 'origin', 'external_reference', 'note', 'reasoning', 'failure_reason',
        'approved_at', 'sent_at', 'settled_at',
    ];

    public function fromProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'from_provider_id');
    }

    public function toProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'to_provider_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TreasuryTransferEvent::class)->orderBy('id');
    }

    public function amount(): BigDecimal
    {
        return Currency::fromMinorUnits($this->amount_minor, $this->currency);
    }

    public function isFx(): bool
    {
        return $this->to_currency !== null && $this->to_currency !== $this->currency;
    }

    public function destinationCurrency(): string
    {
        return $this->to_currency ?: $this->currency;
    }

    public function targetAmount(): BigDecimal
    {
        $currency = $this->destinationCurrency();

        return Currency::fromMinorUnits($this->target_amount_minor ?? $this->amount_minor, $currency);
    }

    public function receivedAmount(): ?BigDecimal
    {
        if ($this->received_minor === null) {
            return null;
        }

        return Currency::fromMinorUnits($this->received_minor, $this->destinationCurrency());
    }

    protected function casts(): array
    {
        return [
            'status' => TransferStatus::class,
            'amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'received_minor' => 'integer',
            'target_amount_minor' => 'integer',
            'fx_rate' => 'decimal:8',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }
}
