<?php

namespace App\Support\Fees;

use App\Exceptions\NoEligibleProviderException;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\Currency;
use App\Support\Fx\FxQuoter;
use App\Support\ProviderFees;
use App\Support\ProviderRouter;
use Brick\Math\BigDecimal;

/**
 * Quotes a payment's fees for every provider that would route it, in the
 * order the switch would try them.
 */
class PaymentQuoter
{
    /**
     * @return list<ProviderQuote>
     *
     * @throws NoEligibleProviderException
     */
    public function quote(User $user, BigDecimal|int|float|string $amount, string $country, string $currency, bool $allowFx = false): array
    {
        $amount = FeeCalculator::money($amount);
        $providers = ProviderRouter::route($user, $country, $currency, $amount->toFloat(), allowFx: $allowFx);

        return $providers
            ->map(fn (PaymentProvider $provider): ProviderQuote => $this->quoteProvider($provider, $amount, $currency))
            ->values()
            ->all();
    }

    /**
     * Both views of the fees for one provider.
     */
    public function quoteProvider(PaymentProvider $provider, BigDecimal $amount, string $currency): ProviderQuote
    {
        $schedule = ProviderFees::scheduleFor($provider);
        $chargeCurrency = $currency;
        $chargeAmount = $amount;
        $fxData = null;

        if (! Currency::isSupportedBy($provider->class, '', $currency) && ProviderRouter::supportsFx($provider->class, $currency)) {
            $base = config('moneyunify_paypal.fx.base_currency', 'USD');
            $quoter = app(FxQuoter::class);
            $quote = $quoter->quote($currency, $base, $amount);
            $chargeCurrency = $base;
            $chargeAmount = $quote->targetAmount;
            $fxData = $quote->toArray();
        }

        if ($schedule === null) {
            return new ProviderQuote($provider, $chargeCurrency, null, null, $fxData);
        }

        $calculator = new FeeCalculator($schedule, $chargeCurrency);

        return new ProviderQuote(
            $provider,
            $chargeCurrency,
            merchantPaysFees: $calculator->forPayerAmount($chargeAmount),
            payerPaysFees: $calculator->forMerchantAmount($chargeAmount),
            fx: $fxData,
        );
    }
}
