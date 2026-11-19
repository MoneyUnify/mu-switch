<?php

namespace App\Support\CrossBorder;

use App\Models\PaymentProvider;
use App\Support\Fx\FxQuote;
use Illuminate\Support\Collection;

/**
 * Everything a cross-border transfer needs before it starts: the locked FX,
 * the PayPal account that collects, the payout providers that deliver (in the
 * order they'll be tried), and the estimated fees the operator absorbs.
 */
final class CrossBorderQuote
{
    /**
     * @param  Collection<int, PaymentProvider>  $payoutRoute
     */
    public function __construct(
        public readonly FxQuote $fx,
        public readonly PaymentProvider $funding,
        public readonly Collection $payoutRoute,
        public readonly string $country,
        public readonly ?string $fundingFee,
        public readonly ?string $payoutFee,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $fx = $this->fx;

        return [
            'fx_quote_id' => $fx->id,
            'sender_pays' => ['amount' => (string) $fx->sourceAmount, 'currency' => $fx->sourceCurrency],
            'recipient_gets' => ['amount' => (string) $fx->targetAmount, 'currency' => $fx->targetCurrency],
            'fx' => [
                'base_rate' => (string) $fx->baseRate,
                'effective_rate' => (string) $fx->effectiveRate,
                'margin_percent' => (string) $fx->marginPercent,
                'margin_amount' => (string) $fx->marginAmount,
                'rate_source' => $fx->rateSource,
            ],
            // Provider fees are paid by the operator from its own accounts, not added for the sender.
            'operator_fees' => [
                'paypal' => $this->fundingFee === null ? null : ['amount' => $this->fundingFee, 'currency' => $fx->sourceCurrency],
                'payout' => $this->payoutFee === null ? null : ['amount' => $this->payoutFee, 'currency' => $fx->targetCurrency],
                'estimated' => true,
            ],
            'funding_provider' => $this->funding->name,
            'payout_route' => $this->payoutRoute->pluck('name')->values()->all(),
            'country' => $this->country,
            'expires_at' => $fx->expiresAt->toIso8601String(),
        ];
    }
}
