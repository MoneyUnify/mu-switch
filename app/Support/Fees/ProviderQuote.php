<?php

namespace App\Support\Fees;

use App\Models\PaymentProvider;

/**
 * One provider's fees for a payment, from both sides: when the merchant
 * absorbs the fees, and when the payer is charged extra to cover them.
 * Fees are null when the provider publishes no fee schedule.
 */
final class ProviderQuote
{
    public function __construct(
        public readonly PaymentProvider $provider,
        public readonly string $currency,
        public readonly ?FeeBreakdown $merchantPaysFees,
        public readonly ?FeeBreakdown $payerPaysFees,
        public readonly ?array $fx = null,
    ) {}

    public function feesKnown(): bool
    {
        return $this->merchantPaysFees !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->name,
            'provider_id' => $this->provider->id,
            'currency' => $this->currency,
            'fees_known' => $this->feesKnown(),
            // Quotes come from published schedules; actual fees are recorded once paid.
            'estimated' => true,
            'is_fx' => $this->fx !== null,
            'fx' => $this->fx,
            'merchant_pays_fees' => $this->merchantPaysFees?->toArray(),
            'payer_pays_fees' => $this->payerPaysFees?->toArray(),
        ];
    }
}
