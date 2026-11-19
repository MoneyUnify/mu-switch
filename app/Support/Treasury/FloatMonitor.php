<?php

namespace App\Support\Treasury;

use App\Models\FloatAlert;
use App\Models\FloatRule;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\Currency;
use App\Support\Ledger\LedgerBalance;

/**
 * Compares provider account balances with their float rules. A balance below
 * the minimum keeps exactly one open alert, which resolves once it recovers.
 */
class FloatMonitor
{
    /**
     * Re-check one account's rule, e.g. after its balance changed.
     */
    public function checkAccount(PaymentProvider $provider, string $currency): void
    {
        $rule = FloatRule::query()
            ->where('payment_provider_id', $provider->id)
            ->where('currency', strtoupper($currency))
            ->first();

        if ($rule) {
            $this->check($rule);
        }
    }

    /**
     * Open, refresh or resolve the rule's alert. Returns the open alert, if any.
     */
    public function check(FloatRule $rule): ?FloatAlert
    {
        $balance = Currency::toMinorUnits(LedgerBalance::of($rule->paymentProvider, $rule->currency), $rule->currency);
        $open = $rule->openAlert()->first();

        if ($rule->is_active && $balance < $rule->minimum_minor) {
            if ($open) {
                $open->update(['balance_minor' => $balance, 'minimum_minor' => $rule->minimum_minor]);

                return $open;
            }

            return $rule->alerts()->create([
                'balance_minor' => $balance,
                'minimum_minor' => $rule->minimum_minor,
                'opened_at' => now(),
            ]);
        }

        $open?->update(['resolved_at' => now()]);

        return null;
    }

    /**
     * Re-check every rule (or one account's), returning how many are low.
     */
    public function checkAll(?User $user = null): int
    {
        $low = 0;

        FloatRule::query()
            ->with('paymentProvider')
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->each(function (FloatRule $rule) use (&$low): void {
                if ($this->check($rule)) {
                    $low++;
                }
            });

        return $low;
    }
}
