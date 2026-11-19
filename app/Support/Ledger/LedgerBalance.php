<?php

namespace App\Support\Ledger;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\PaymentProvider;
use App\Support\Currency;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Provider account balances, read from the ledger. Sums are over whole
 * minor units, so they are exact.
 */
class LedgerBalance
{
    /**
     * The exact balance of one provider account in one currency.
     */
    public static function of(PaymentProvider $provider, string $currency): BigDecimal
    {
        $minor = (int) LedgerEntry::query()
            ->where('payment_provider_id', $provider->id)
            ->where('account', LedgerAccount::PROVIDER->value)
            ->where('currency', strtoupper($currency))
            ->sum('amount_minor');

        return Currency::fromMinorUnits($minor, $currency);
    }

    /**
     * Per provider and currency: money in, money out (fees and payouts), and
     * the balance, plus whether any fee in it is an estimate.
     *
     * @param  iterable<int>  $providerIds
     * @return Collection<int, list<array{currency: string, count: int, inflow: float, outflow: float, net: float, estimated: bool}>>
     */
    public static function summaries(iterable $providerIds): Collection
    {
        $settled = [LedgerEntryType::COLLECTION->value, LedgerEntryType::PAYOUT->value];

        return LedgerEntry::query()
            ->whereIn('payment_provider_id', collect($providerIds))
            ->where('account', LedgerAccount::PROVIDER->value)
            ->selectRaw('payment_provider_id, currency')
            ->selectRaw('coalesce(sum(case when amount_minor > 0 then amount_minor else 0 end), 0) as inflow_minor')
            ->selectRaw('coalesce(sum(case when amount_minor < 0 then -amount_minor else 0 end), 0) as outflow_minor')
            ->selectRaw('coalesce(sum(amount_minor), 0) as net_minor')
            ->selectRaw('count(distinct case when type in (?, ?) then source_id end) as settled_count', $settled)
            ->selectRaw('max(case when estimated then 1 else 0 end) as estimated')
            ->groupBy('payment_provider_id', 'currency')
            ->orderByDesc('inflow_minor')
            ->get()
            ->groupBy('payment_provider_id')
            ->map(fn (Collection $rows): array => $rows->map(fn ($row): array => [
                'currency' => (string) $row->currency,
                'count' => (int) $row->settled_count,
                'inflow' => Currency::fromMinorUnits((int) $row->inflow_minor, $row->currency)->toFloat(),
                'outflow' => Currency::fromMinorUnits((int) $row->outflow_minor, $row->currency)->toFloat(),
                'net' => Currency::fromMinorUnits((int) $row->net_minor, $row->currency)->toFloat(),
                'estimated' => (bool) $row->estimated,
            ])->values()->all());
    }
}
