<?php

namespace App\Models;

use App\Support\Currency;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * "Keep at least this much in this provider account and currency."
 */
class FloatRule extends Model
{
    protected $fillable = ['user_id', 'payment_provider_id', 'currency', 'minimum_minor', 'target_minor', 'is_active'];

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(FloatAlert::class);
    }

    /**
     * The alert raised while the balance is below the minimum, if any.
     */
    public function openAlert(): HasOne
    {
        return $this->hasOne(FloatAlert::class)->whereNull('resolved_at')->latestOfMany();
    }

    public function minimum(): BigDecimal
    {
        return Currency::fromMinorUnits($this->minimum_minor, $this->currency);
    }

    public function target(): ?BigDecimal
    {
        return $this->target_minor === null ? null : Currency::fromMinorUnits($this->target_minor, $this->currency);
    }

    protected function casts(): array
    {
        return [
            'minimum_minor' => 'integer',
            'target_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
