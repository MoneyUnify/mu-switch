<?php

namespace App\Models;

use App\Support\Currency;
use Database\Factories\PaymentLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A shareable link a merchant's customers pay through, with the currencies
 * they may pay in, the currency the merchant is settled in, and optionally a
 * fixed price. Deleting a link that took payments archives it instead.
 */
class PaymentLink extends Model
{
    /** @use HasFactory<PaymentLinkFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'merchant_id', 'code', 'label', 'pay_currencies', 'settle_currency',
        'amount_minor', 'reference', 'is_active',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * The payments made through this link.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(CrossBorderTransfer::class);
    }

    /**
     * A short, unguessable code for the link's public URL.
     */
    public static function newCode(): string
    {
        do {
            $code = Str::lower(Str::random(10));
        } while (self::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /**
     * The fixed price, as an exact decimal string, or null.
     */
    public function amount(): ?string
    {
        return $this->amount_minor === null ? null : (string) Currency::fromMinorUnits($this->amount_minor, $this->settle_currency);
    }

    public function url(): string
    {
        return route('pay.link', $this->code);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    protected function casts(): array
    {
        return [
            'pay_currencies' => 'array',
            'amount_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
