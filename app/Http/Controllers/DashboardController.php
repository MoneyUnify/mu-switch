<?php

namespace App\Http\Controllers;

use App\Enums\TransactionStatus;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Number of days in the primary reporting window.
     */
    private const PERIOD_DAYS = 30;

    /**
     * Number of days rendered in the volume trend chart.
     */
    private const TREND_DAYS = 14;

    /**
     * Render the payment switch operations dashboard.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $providers = $user->paymentProviders()->get();
        $providerIds = $providers->pluck('id');

        $now = Carbon::now();
        $periodStart = $now->copy()->subDays(self::PERIOD_DAYS);
        $previousStart = $now->copy()->subDays(self::PERIOD_DAYS * 2);

        return Inertia::render('dashboard', [
            'apiToken' => $user->ensureApiToken(),
            'currency' => $this->dominantCurrency($providerIds),
            'hasProviders' => $providers->isNotEmpty(),
            'stats' => $this->stats($providerIds, $periodStart, $previousStart, $providers),
            'volumeTrend' => $this->volumeTrend($providerIds),
            'statusBreakdown' => $this->statusBreakdown($providerIds, $periodStart),
            'providerPerformance' => $this->providerPerformance($providers, $providerIds, $periodStart),
            'recentTransactions' => $this->recentTransactions($providerIds, $request),
            'transactionFilters' => [
                'q' => trim($request->string('tx')->toString()) ?: null,
            ],
        ]);
    }

    /**
     * Headline KPI cards comparing the current window to the previous one.
     *
     * @param  Collection<int, int>  $providerIds
     * @param  Collection<int, PaymentProvider>  $providers
     * @return array<string, mixed>
     */
    private function stats(Collection $providerIds, Carbon $periodStart, Carbon $previousStart, Collection $providers): array
    {
        $current = $this->windowAggregate($providerIds, $periodStart, Carbon::now());
        $previous = $this->windowAggregate($providerIds, $previousStart, $periodStart);

        $currentSuccessRate = $current['total'] > 0 ? ($current['success'] / $current['total']) * 100 : 0.0;
        $previousSuccessRate = $previous['total'] > 0 ? ($previous['success'] / $previous['total']) * 100 : 0.0;

        return [
            'volume' => [
                'value' => round((float) $current['volume'], 2),
                'change' => $this->percentChange((float) $current['volume'], (float) $previous['volume']),
            ],
            'transactions' => [
                'value' => (int) $current['total'],
                'change' => $this->percentChange((float) $current['total'], (float) $previous['total']),
            ],
            'successRate' => [
                'value' => round($currentSuccessRate, 1),
                'change' => round($currentSuccessRate - $previousSuccessRate, 1),
            ],
            'activeProviders' => [
                'value' => $providers->where('is_active', true)->count(),
                'total' => $providers->count(),
            ],
            'failed' => [
                'value' => (int) $current['failed'],
                'change' => $this->percentChange((float) $current['failed'], (float) $previous['failed']),
            ],
        ];
    }

    /**
     * Count / success / failed / volume totals for a single time window.
     *
     * @param  Collection<int, int>  $providerIds
     * @return array{total: int, success: int, failed: int, volume: float}
     */
    private function windowAggregate(Collection $providerIds, Carbon $from, Carbon $to): array
    {
        $row = Transaction::query()
            ->whereIn('payment_provider_id', $providerIds)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('count(*) as total')
            ->selectRaw("coalesce(sum(case when status = 'success' then 1 else 0 end), 0) as success")
            ->selectRaw("coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as failed")
            ->selectRaw("coalesce(sum(case when status = 'success' then amount else 0 end), 0) as volume")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'success' => (int) ($row->success ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'volume' => (float) ($row->volume ?? 0),
        ];
    }

    /**
     * Daily transaction count and successful volume for the trend chart.
     *
     * @param  Collection<int, int>  $providerIds
     * @return list<array{date: string, label: string, count: int, volume: float}>
     */
    private function volumeTrend(Collection $providerIds): array
    {
        $start = Carbon::now()->subDays(self::TREND_DAYS - 1)->startOfDay();

        $rows = Transaction::query()
            ->whereIn('payment_provider_id', $providerIds)
            ->where('created_at', '>=', $start)
            ->selectRaw('date(created_at) as day')
            ->selectRaw('count(*) as count')
            ->selectRaw("coalesce(sum(case when status = 'success' then amount else 0 end), 0) as volume")
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        return collect(range(0, self::TREND_DAYS - 1))
            ->map(function (int $offset) use ($start, $rows): array {
                $date = $start->copy()->addDays($offset);
                $key = $date->format('Y-m-d');
                $row = $rows->get($key);

                return [
                    'date' => $key,
                    'label' => $date->format('M j'),
                    'count' => (int) ($row->count ?? 0),
                    'volume' => round((float) ($row->volume ?? 0), 2),
                ];
            })
            ->all();
    }

    /**
     * Transaction counts grouped by status for the current window.
     *
     * @param  Collection<int, int>  $providerIds
     * @return array<string, int>
     */
    private function statusBreakdown(Collection $providerIds, Carbon $periodStart): array
    {
        $counts = Transaction::query()
            ->whereIn('payment_provider_id', $providerIds)
            ->where('created_at', '>=', $periodStart)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $breakdown = [];

        foreach (TransactionStatus::cases() as $status) {
            $breakdown[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $breakdown;
    }

    /**
     * Per-provider reliability and throughput metrics.
     *
     * @param  Collection<int, PaymentProvider>  $providers
     * @param  Collection<int, int>  $providerIds
     * @return list<array<string, mixed>>
     */
    private function providerPerformance(Collection $providers, Collection $providerIds, Carbon $periodStart): array
    {
        $stats = Transaction::query()
            ->whereIn('payment_provider_id', $providerIds)
            ->where('created_at', '>=', $periodStart)
            ->selectRaw('payment_provider_id')
            ->selectRaw('count(*) as total')
            ->selectRaw("coalesce(sum(case when status = 'success' then 1 else 0 end), 0) as success")
            ->selectRaw("coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as failed")
            ->selectRaw("coalesce(sum(case when status = 'pending' then 1 else 0 end), 0) as pending")
            ->selectRaw("coalesce(sum(case when status = 'success' then amount else 0 end), 0) as volume")
            ->selectRaw('max(created_at) as last_at')
            ->groupBy('payment_provider_id')
            ->get()
            ->keyBy('payment_provider_id');

        return $providers
            ->map(function ($provider) use ($stats): array {
                $row = $stats->get($provider->id);
                $total = (int) ($row->total ?? 0);
                $success = (int) ($row->success ?? 0);
                $lastAt = $row->last_at ?? null;

                return [
                    'id' => $provider->id,
                    'name' => $provider->name,
                    'logo_url' => $provider->logo_url,
                    'is_active' => (bool) $provider->is_active,
                    'total' => $total,
                    'success' => $success,
                    'failed' => (int) ($row->failed ?? 0),
                    'pending' => (int) ($row->pending ?? 0),
                    'successRate' => $total > 0 ? round(($success / $total) * 100, 1) : null,
                    'volume' => round((float) ($row->volume ?? 0), 2),
                    'lastActivity' => $lastAt ? Carbon::parse($lastAt)->diffForHumans() : null,
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * The user's transactions across all providers — searchable and paginated.
     *
     * @param  Collection<int, int>  $providerIds
     */
    private function recentTransactions(Collection $providerIds, Request $request): LengthAwarePaginator
    {
        $search = trim($request->string('tx')->toString());

        return Transaction::query()
            ->whereIn('payment_provider_id', $providerIds)
            ->with(['paymentProvider:id,name,logo_url', 'customer:id,name,email'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function (Builder $inner) use ($like, $search): void {
                    $inner->where('transaction_id', 'like', $like)
                        ->orWhere('currency', 'like', $like)
                        ->orWhere('status', 'like', $like)
                        ->when(is_numeric($search), fn (Builder $q) => $q->orWhere('amount', $search))
                        ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like)->orWhere('email', 'like', $like))
                        ->orWhereHas('paymentProvider', fn (Builder $p) => $p->where('name', 'like', $like));
                });
            })
            ->latest()
            ->paginate(10, ['*'], 'txns')
            ->withQueryString()
            ->through(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'reference' => $transaction->transaction_id,
                'provider' => $transaction->paymentProvider?->name,
                'customer' => $transaction->customer?->name ?? $transaction->customer?->email,
                'amount' => round((float) $transaction->amount, 2),
                'currency' => $transaction->currency,
                'status' => $transaction->status->value,
                'direction' => $transaction->direction,
                'isFx' => (bool) $transaction->is_fx,
                'createdAt' => $transaction->created_at?->diffForHumans(),
            ]);
    }

    /**
     * The currency used by the most successful transactions, for KPI labelling.
     *
     * @param  Collection<int, int>  $providerIds
     */
    private function dominantCurrency(Collection $providerIds): string
    {
        return Transaction::query()
            ->whereIn('payment_provider_id', $providerIds)
            ->where('status', TransactionStatus::SUCCESS->value)
            ->selectRaw('currency, count(*) as count')
            ->groupBy('currency')
            ->orderByDesc('count')
            ->value('currency') ?? 'USD';
    }

    /**
     * Percentage change between two values, or null when not comparable.
     */
    private function percentChange(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current > 0.0 ? 100.0 : null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
