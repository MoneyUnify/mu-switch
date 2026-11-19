<?php

namespace App\Models;

use App\Enums\CrossBorderStatus;
use App\Support\Currency;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money paid in through PayPal and delivered to a mobile wallet in another
 * currency, with the exchange rate locked when it was quoted.
 */
class CrossBorderTransfer extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'merchant_id', 'payment_link_id', 'idempotency_key', 'origin', 'conversation_id', 'commission_minor', 'payer_email',
        'funding_provider_id', 'funding_transaction_id', 'source_currency', 'source_amount_minor',
        'payout_provider_id', 'payout_transaction_id', 'target_currency', 'target_amount_minor',
        'recipient_country', 'recipient_account', 'recipient_name', 'recipient_email', 'recipient_network', 'narration',
        'fx_quote_id', 'fx_base_rate', 'fx_rate', 'fx_margin_percent', 'fx_margin_minor', 'fx_rate_source',
        'refund_transaction_id', 'status', 'failure_reason',
        'funded_at', 'delivered_at', 'refunded_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function paymentLink(): BelongsTo
    {
        return $this->belongsTo(PaymentLink::class)->withTrashed();
    }

    public function fundingProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'funding_provider_id');
    }

    public function fundingTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'funding_transaction_id');
    }

    public function payoutProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'payout_provider_id');
    }

    public function payoutTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'payout_transaction_id');
    }

    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'refund_transaction_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(CrossBorderTransferEvent::class)->orderBy('id');
    }

    public function sourceAmount(): BigDecimal
    {
        return Currency::fromMinorUnits($this->source_amount_minor, $this->source_currency);
    }

    public function targetAmount(): BigDecimal
    {
        return Currency::fromMinorUnits($this->target_amount_minor, $this->target_currency);
    }

    protected function casts(): array
    {
        return [
            'status' => CrossBorderStatus::class,
            'source_amount_minor' => 'integer',
            'target_amount_minor' => 'integer',
            'fx_margin_minor' => 'integer',
            'commission_minor' => 'integer',
            'funded_at' => 'datetime',
            'delivered_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }
}
