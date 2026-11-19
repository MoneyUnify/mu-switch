<?php

namespace App\Http\Controllers;

use App\Contracts\RefundsPayments;
use App\Enums\TransactionStatus;
use App\Exceptions\RefundException;
use App\Models\CrossBorderTransfer;
use App\Models\Transaction;
use App\Support\Currency;
use App\Support\CurrencyVolumes;
use App\Support\Fees\FeeCalculator;
use App\Support\Market;
use App\Support\Refunds\PaymentRefunder;
use App\Support\SqlDate;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
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

        $metrics = $this->metrics($inWindow());
        $metrics['volumes'] = CurrencyVolumes::padded(
            $metrics['volumes'],
            $this->recentCurrencies($base()),
            CurrencyVolumes::supportedBy($request->user()->paymentProviders()->get()),
        );

        return Inertia::render('payments/index', [
            'metrics' => $metrics,
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
            $inner->whereLike('transaction_id', $like)
                ->orWhereLike('provider_transaction_id', $like)
                ->orWhereLike('currency', $like)
                ->orWhereLike('country', $like)
                ->orWhereLike('status', $like)
                ->when(is_numeric($search), fn (Builder $q) => $q->orWhere('amount', $search))
                ->orWhereHas('customer', fn (Builder $c) => $c->whereLike('name', $like)->orWhereLike('email', $like))
                ->orWhereHas('customer.accounts', fn (Builder $a) => $a->whereLike('number', $like))
                ->orWhereHas('paymentProvider', fn (Builder $p) => $p->whereLike('name', $like));
        });
    }

    /**
     * Headline metrics for the current window.
     *
     * @return array<string, mixed>
     */
    private function metrics(Builder $query): array
    {
        // Before the counts below add their selects to the query.
        $volumes = $this->volumes(clone $query);

        $row = $query
            ->selectRaw('count(*) as total')
            ->selectRaw("coalesce(sum(case when status = 'success' then 1 else 0 end), 0) as success")
            ->selectRaw("coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as failed")
            ->selectRaw("coalesce(sum(case when status = 'pending' then 1 else 0 end), 0) as pending")
            ->first();

        $total = (int) ($row->total ?? 0);
        $success = (int) ($row->success ?? 0);

        return [
            'total' => $total,
            'success' => $success,
            'failed' => (int) ($row->failed ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'volumes' => $volumes,
            'successRate' => $total > 0 ? round(($success / $total) * 100, 1) : null,
        ];
    }

    /**
     * Currencies this account has transacted in, most recently used first.
     *
     * @return list<string>
     */
    private function recentCurrencies(Builder $query): array
    {
        return $query
            ->whereNotNull('currency')
            ->selectRaw('currency, max(created_at) as last_used')
            ->groupBy('currency')
            ->orderByDesc('last_used')
            ->pluck('currency')
            ->all();
    }

    /**
     * Successful volume in each currency, busiest first. Currencies are never
     * added together: a mixed total has no single unit.
     *
     * @return list<array{currency: string, amount: float, count: int}>
     */
    private function volumes(Builder $query): array
    {
        return $query
            ->where('status', 'success')
            ->whereNotNull('currency')
            ->selectRaw('currency')
            ->selectRaw('count(*) as count')
            ->selectRaw('coalesce(sum(amount), 0) as amount')
            ->groupBy('currency')
            ->get()
            ->map(fn ($row): array => [
                'currency' => strtoupper((string) $row->currency),
                'amount' => round((float) $row->amount, Currency::decimals((string) $row->currency)),
                'count' => (int) $row->count,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
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
            ->selectRaw(SqlDate::bucket($query).' as bucket')
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
                'refunds',
                'paymentProvider:id,name,class,logo_url',
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
                'fees' => $this->feeBreakdown($transaction),
                'refund' => $this->refundSummary($transaction),
                'createdAtHuman' => $transaction->created_at?->diffForHumans(),
                'createdAt' => $transaction->created_at?->format('Y-m-d H:i:s T'),
            ]);
    }

    /**
     * Refunds so far, what can still be refunded, and why not when it can't.
     *
     * @return array{refunded: string, refundable: string, canRefund: bool, blockedReason: ?string, items: list<array<string, mixed>>}
     */
    private function refundSummary(Transaction $transaction): array
    {
        $class = $transaction->paymentProvider?->class;
        $transfer = CrossBorderTransfer::query()->where('funding_transaction_id', $transaction->id)->value('reference');

        $blocked = match (true) {
            ! $class || ! is_subclass_of($class, RefundsPayments::class) => 'This provider does not support refunds.',
            $transfer !== null => 'This payment funds a cross-border transfer, which refunds it automatically if delivery fails.',
            $transaction->status !== TransactionStatus::SUCCESS => 'Only completed payments can be refunded.',
            default => null,
        };

        // Sums from the loaded refunds, so the list doesn't query per row.
        $minor = fn (array $statuses): int => $transaction->refunds
            ->filter(fn (Transaction $refund): bool => in_array($refund->status, $statuses, true))
            ->sum(fn (Transaction $refund): int => Currency::toMinorUnits((string) $refund->amount, $transaction->currency));
        $paid = Currency::toMinorUnits((string) $transaction->amount, $transaction->currency);
        $refundable = (string) Currency::fromMinorUnits(max(0, $paid - $minor([TransactionStatus::SUCCESS, TransactionStatus::PENDING])), $transaction->currency);

        return [
            'refunded' => (string) Currency::fromMinorUnits($minor([TransactionStatus::SUCCESS]), $transaction->currency),
            'refundable' => $refundable,
            'canRefund' => $blocked === null && (float) $refundable > 0,
            'blockedReason' => $blocked,
            'items' => $transaction->refunds->map(fn (Transaction $refund): array => [
                'id' => $refund->transaction_id,
                'amount' => (string) Currency::fromMinorUnits(Currency::toMinorUnits((string) $refund->amount, $transaction->currency), $transaction->currency),
                'status' => $refund->status->value,
                'reason' => $refund->provider_response['reason'] ?? null,
                'fromPaypal' => ($refund->provider_response['source'] ?? null) === 'paypal',
                'createdAt' => $refund->created_at?->diffForHumans(),
            ])->values()->all(),
        ];
    }

    /**
     * Refund a payment from the dashboard, in full or in part.
     */
    public function refund(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_unless($request->user()->paymentProviders()->whereKey($transaction->payment_provider_id)->exists(), 404);

        $validated = $request->validate([
            'amount' => 'nullable|numeric|gt:0',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $refund = app(PaymentRefunder::class)->refund($transaction, isset($validated['amount']) ? (string) $validated['amount'] : null, $validated['reason'] ?? null);
        } catch (RefundException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', match ($refund->status) {
            TransactionStatus::SUCCESS => ['type' => 'success', 'message' => __('Refunded :amount :currency.', ['amount' => (string) Currency::fromMinorUnits(Currency::toMinorUnits((string) $refund->amount, $refund->currency), $refund->currency), 'currency' => $refund->currency])],
            TransactionStatus::FAILED => ['type' => 'error', 'message' => __('The refund was refused: :reason', ['reason' => data_get($refund->provider_response, 'error.message', 'no reason given')])],
            default => ['type' => 'success', 'message' => __('Refund requested. PayPal is processing it.')],
        });

        return back();
    }

    /**
     * The transaction's fees and what the merchant nets, as exact strings in
     * the currency's precision, or null when the provider has no fee data.
     *
     * @return array{collection: string, settlement: string, total: string, net: string, estimated: bool}|null
     */
    private function feeBreakdown(Transaction $transaction): ?array
    {
        if ($transaction->collection_fee === null && $transaction->settlement_fee === null) {
            return null;
        }

        $decimals = Currency::decimals((string) $transaction->currency);
        $format = fn (mixed $value): string => (string) FeeCalculator::money((string) ($value ?? 0))->toScale($decimals, RoundingMode::HalfUp);

        $collection = FeeCalculator::money((string) ($transaction->collection_fee ?? 0));
        $settlement = FeeCalculator::money((string) ($transaction->settlement_fee ?? 0));

        return [
            'collection' => $format($collection),
            'settlement' => $format($settlement),
            'total' => $format($collection->plus($settlement)),
            'net' => $format($transaction->net_amount),
            'estimated' => (bool) $transaction->fee_estimated,
        ];
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
