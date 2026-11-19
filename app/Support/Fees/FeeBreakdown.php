<?php

namespace App\Support\Fees;

use App\Support\Currency;
use Brick\Math\BigDecimal;

/**
 * Who pays what for one payment in one currency: what the payer is charged,
 * the provider's fees, and what the merchant receives.
 */
final class FeeBreakdown
{
    public function __construct(
        public readonly string $currency,
        public readonly BigDecimal $payerPays,
        public readonly BigDecimal $collectionFee,
        public readonly BigDecimal $settlementFee,
    ) {}

    public function totalFees(): BigDecimal
    {
        return $this->collectionFee->plus($this->settlementFee);
    }

    public function merchantReceives(): BigDecimal
    {
        return $this->payerPays->minus($this->totalFees());
    }

    /**
     * Amounts as fixed-scale strings, so no precision is lost in JSON.
     *
     * @return array{payer_pays: string, fees: array{collection: string, settlement: string, total: string}, merchant_receives: string}
     */
    public function toArray(): array
    {
        $scale = Currency::decimals($this->currency);

        return [
            'payer_pays' => (string) $this->payerPays->toScale($scale),
            'fees' => [
                'collection' => (string) $this->collectionFee->toScale($scale),
                'settlement' => (string) $this->settlementFee->toScale($scale),
                'total' => (string) $this->totalFees()->toScale($scale),
            ],
            'merchant_receives' => (string) $this->merchantReceives()->toScale($scale),
        ];
    }
}
