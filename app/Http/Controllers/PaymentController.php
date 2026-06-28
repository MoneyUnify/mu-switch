<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Support\Market;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    /**
     * Selectable reporting windows.
     *
     * @var list<string>
     */
    private const RANGES = ['today', '7d', '30d', 'all'];

    /**
     * Selectable page sizes for the records table (first entry is the default).
     *
     * @var list<int>
     */
    private const PER_PAGE_OPTIONS = [15, 30, 50, 100];

    /**
     * A holistic monitoring view of request-to-pay (collection) transactions:
     * headline metrics, the busiest currencies and countries, and a searchable,
     * filterable, paginated record of every processed transaction.
     */
    public function index(Request $request): Response
    {
        $providerIds = $request->user()->paymentProviders()->pluck('id');

        $range = $request->string('range')->toString();
        $range = in_array($range, self::RANGES, true) ? $range : '30d';
        [$from, $to] = $this->resolveRange($range);

        $search = trim($request->string('q')->toString());

        $status = $request->string('status')->toString();
        $status = in_array($status, ['success', 'failed', 'pending'], true) ? $status : null;

        $perPage = (int) $request->integer('perPage');
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::PER_PAGE_OPTIONS[0];

        // The account's request-to-pay (credit) transactions matching the search.
        $base = fn (): Builder => Transaction::query()
            ->whereIn('payment_provider_id', $providerIds)
            ->where('direction', 'credit')
            ->tap(fn (Builder $q) => $this->applySearch($q, $search));

        // The same, narrowed to the selected window — metrics and top lists use
        // this (but NOT the status filter, so they reflect the whole window).
        $inWindow = fn (): Builder => $base()->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from));

        return Inertia::render('payments/index', [
            'metrics' => $this->metrics($inWindow()),
            'metricTrend' => $this->metricTrend($base()),
            'topCurrencies' => $this->topCurrencies($inWindow()),
            'topCountries' => $this->topCountries($inWindow()),
            'transactions' => $this->paginatedTransactions($inWindow(), $status, $perPage),
            'filters' => [
                'range' => $range,
                'status' => $status,
                'q' => $search ?: null,
                'perPage' => $perPage,
            ],
            'rangeOptions' => self::RANGES,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    /**
     * Resolve a range key into a [from, to] tuple (from is null for "all").
     *
     * @return array{0: ?Carbon, 1: Carbon}
     */
    private function resolveRange(string $range): array
    {
        $now = Carbon::now();

        return match ($range) {
            'today' => [$now->copy()->startOfDay(), $now],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now],
            'all' => [null, $now],
            default => [$now->copy()->subDays(29)->startOfDay(), $now],
        };
    }

    /**
     * Apply the free-text search across reference, money, customer and provider.
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';

        $query->where(function (Builder $inner) use ($like, $search): void {
            $inner->where('transaction_id', 'like', $like)
                ->orWhere('provider_transaction_id', 'like', $like)
                ->orWhere('currency', 'like', $like)
                ->orWhere('country', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->when(is_numeric($search), fn (Builder $q) => $q->orWhere('amount', $search))
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orWhereHas('customer.accounts', fn (Builder $a) => $a->where('number', 'like', $like))
                ->orWhereHas('paymentProvider', fn (Builder $p) => $p->where('name', 'like', $like));
        });
    }

    /**
     * Headline metrics for the current window.
     *
     * @return array<string, mixed>
     */
    private function metrics(Builder $query): array
    {
        $row = $query
            ->selectRaw('count(*) as total')
            ->selectRaw("coalesce(sum(case when status = 'success' then 1 else 0 end), 0) as success")
            ->selectRaw("coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as failed")
            ->selectRaw("coalesce(sum(case when status = 'pending' then 1 else 0 end), 0) as pending")
            ->selectRaw("coalesce(sum(case when status = 'success' then amount else 0 end), 0) as volume")
            ->first();

        $total = (int) ($row->total ?? 0);
        $success = (int) ($row->success ?? 0);

        return [
            'total' => $total,
            'success' => $success,
            'failed' => (int) ($row->failed ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'volume' => round((float) ($row->volume ?? 0), 2),
            'successRate' => $total > 0 ? round(($success / $total) * 100, 1) : null,
        ];
    }

    /**
     * Daily per-status transaction counts over the last fortnight, for the KPI
     * card sparklines. Independent of the selected window — it always shows the
     * recent momentum behind each metric.
     *
     * @return array{total: list<int>, success: list<int>, pending: list<int>, failed: list<int>}
     */
    private function metricTrend(Builder $query): array
    {
        $days = 14;
        $start = Carbon::now()->subDays($days - 1)->startOfDay();

        $rows = $query
            ->where('created_at', '>=', $start)
            ->selectRaw("strftime('%Y-%m-%d', created_at) as bucket")
            ->selectRaw('count(*) as total')
            ->selectRaw("coalesce(sum(case when status = 'success' then 1 else 0 end), 0) as success")
            ->selectRaw("coalesce(sum(case when status = 'pending' then 1 else 0 end), 0) as pending")
            ->selectRaw("coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as failed")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $series = ['total' => [], 'success' => [], 'pending' => [], 'failed' => []];

        for ($i = 0; $i < $days; $i++) {
            $row = $rows->get($start->copy()->addDays($i)->format('Y-m-d'));
            $series['total'][] = (int) ($row->total ?? 0);
            $series['success'][] = (int) ($row->success ?? 0);
            $series['pending'][] = (int) ($row->pending ?? 0);
            $series['failed'][] = (int) ($row->failed ?? 0);
        }

        return $series;
    }

    /**
     * The six currencies with the most transactions in the window.
     *
     * @return list<array{currency: string, count: int, volume: float}>
     */
    private function topCurrencies(Builder $query): array
    {
        return $query
            ->selectRaw('currency')
            ->selectRaw('count(*) as count')
            ->selectRaw("coalesce(sum(case when status = 'success' then amount else 0 end), 0) as volume")
            ->whereNotNull('currency')
            ->groupBy('currency')
            ->orderByDesc('count')
            ->limit(6)
            ->get()
            ->map(fn ($row): array => [
                'currency' => (string) $row->currency,
                'count' => (int) $row->count,
                'volume' => round((float) $row->volume, 2),
            ])
            ->all();
    }

    /**
     * The six countries with the most transactions in the window.
     *
     * @return list<array{code: string, name: string, count: int}>
     */
    private function topCountries(Builder $query): array
    {
        return $query
            ->selectRaw('country')
            ->selectRaw('count(*) as count')
            ->whereNotNull('country')
            ->groupBy('country')
            ->orderByDesc('count')
            ->limit(6)
            ->get()
            ->map(fn ($row): array => [
                'code' => (string) $row->country,
                'name' => Market::name((string) $row->country),
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /**
     * The transaction records, optionally narrowed to a status, paginated.
     */
    private function paginatedTransactions(Builder $query, ?string $status, int $perPage): LengthAwarePaginator
    {
        return $query
            ->when($status, fn (Builder $q) => $q->where('status', $status))
            ->with([
                'paymentProvider:id,name,logo_url',
                'customer:id,name,email',
                'customer.accounts:id,customer_id,number,country',
            ])
            ->latest()
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'reference' => $transaction->transaction_id,
                'providerReference' => $transaction->provider_transaction_id,
                'provider' => $transaction->paymentProvider?->name,
                'providerLogo' => $transaction->paymentProvider?->logo_url,
                'account' => $this->payerAccount($transaction),
                'customerName' => $transaction->customer?->name ?? $transaction->customer?->email,
                'amount' => round((float) $transaction->amount, 2),
                'currency' => $transaction->currency,
                'country' => $transaction->country,
                'countryName' => $transaction->country ? Market::name($transaction->country) : null,
                'status' => $transaction->status->value,
                'isFx' => (bool) $transaction->is_fx,
                'createdAtHuman' => $transaction->created_at?->diffForHumans(),
                'createdAt' => $transaction->created_at?->format('Y-m-d H:i:s T'),
            ]);
    }

    /**
     * The payer's account (mobile-money) number for a transaction — the
     * customer's account in the transaction's country, falling back to any.
     */
    private function payerAccount(Transaction $transaction): ?string
    {
        $accounts = $transaction->customer?->accounts;

        if (! $accounts || $accounts->isEmpty()) {
            return null;
        }

        $match = $transaction->country
            ? $accounts->firstWhere('country', $transaction->country)
            : null;

        return ($match ?? $accounts->first())->number;
    }
}
