<?php

namespace App\Support\CrossBorder;

use App\Enums\CrossBorderStatus;
use App\Exceptions\CrossBorderException;
use App\Models\CrossBorderTransfer;
use App\Models\User;
use App\Support\Currency;
use App\Support\Fx\Contracts\FxRateProviderInterface;
use App\Support\Fx\FxQuote;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Spending caps on cross-border transfers, enforced in code whoever starts
 * the transfer (API, dashboard or AI agent): a maximum per transfer and per
 * day, in USD (config `moneyunify_paypal.limits`).
 */
class TransferLimits
{
    public function __construct(private readonly FxRateProviderInterface $rates) {}

    /**
     * Refuse a transfer that would break a cap.
     *
     * @throws CrossBorderException
     */
    public function check(User $user, FxQuote $fx, bool $daily = true): void
    {
        $perTransfer = BigDecimal::of((string) config('moneyunify_paypal.limits.per_transaction_max_usd', 2000));
        $perDay = BigDecimal::of((string) config('moneyunify_paypal.limits.daily_max_usd', 5000));
        $amount = $this->usd($fx->sourceAmount, $fx->sourceCurrency);

        if ($amount->isGreaterThan($perTransfer)) {
            throw new CrossBorderException("This transfer is about USD {$amount}, over the USD {$perTransfer} limit per transfer.");
        }

        if (! $daily) {
            return;
        }

        $today = $this->sentToday($user);

        if ($today->plus($amount)->isGreaterThan($perDay)) {
            $left = $perDay->minus($today);
            $left = $left->isNegative() ? BigDecimal::zero() : $left;

            throw new CrossBorderException("This transfer would pass the USD {$perDay} daily limit (USD {$left} left today).");
        }
    }

    /**
     * What the account has sent today, in USD. Unpaid transfers don't count.
     */
    private function sentToday(User $user): BigDecimal
    {
        return CrossBorderTransfer::query()
            ->where('user_id', $user->id)
            // Customers paying the account's merchants aren't the account sending money.
            ->whereNull('merchant_id')
            ->where('created_at', '>=', now()->startOfDay())
            ->where('status', '!=', CrossBorderStatus::PAYMENT_FAILED->value)
            ->get(['source_amount_minor', 'source_currency'])
            ->reduce(fn (BigDecimal $sum, CrossBorderTransfer $transfer): BigDecimal => $sum->plus(
                $this->usd(Currency::fromMinorUnits($transfer->source_amount_minor, $transfer->source_currency), $transfer->source_currency)
            ), BigDecimal::zero());
    }

    /**
     * An amount in USD, at the mid-market rate.
     *
     * @throws CrossBorderException
     */
    private function usd(BigDecimal $amount, string $currency): BigDecimal
    {
        if ($currency === 'USD') {
            return $amount->toScale(2, RoundingMode::HalfUp);
        }

        $rate = $this->rates->getRate($currency, 'USD');

        if ($rate === null) {
            throw new CrossBorderException("Can't check the spending limit for {$currency}.");
        }

        return $amount->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp);
    }
}
