<?php

namespace App\Http\Controllers;

use App\Enums\CrossBorderStatus;
use App\Enums\TransactionStatus;
use App\Models\CrossBorderTransfer;
use App\Models\CrossBorderTransferEvent;
use App\Models\Transaction;
use App\Support\Currency;
use App\Support\CurrencyVolumes;
use App\Support\Fees\FeeCalculator;
use App\Support\Market;
use App\Support\SqlDate;
use App\Support\TransactionVerifier;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    /**
     * Selectable reporting windows.
     *
     * @var list<string>
     */
    private const RANGES = ['today', '7d', '30d', 'all'];

    /**
     * Selectable transfer types.
     *
     * @var list<string>
     */
    private const TYPES = ['all', 'cross_border', 'payout'];

    /**
     * Selectable page sizes for the records table.
     *
     * @var list<int>
     */
    private const PER_PAGE_OPTIONS = [15, 30, 50, 100];

    public function __construct(private readonly TransactionVerifier $verifier) {}

    /**
     * A holistic monitoring view of outbound payouts and cross-border transfers:
     * headline metrics, status mix, top corridors and currencies, and a searchable,
     * filterable, paginated record of every transfer.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $providerIds = $user->paymentProviders()->pluck('id');

        $range = $request->string('range')->toString();
        $range = in_array($range, self::RANGES, true) ? $range : '30d';
        [$from, $to] = $this->resolveRange($range);

        $type = $request->string('type')->toString();
        $type = in_array($type, self::TYPES, true) ? $type : 'all';

        $search = trim($request->string('q')->toString());

        $status = $request->string('status')->toString();
        $status = in_array($status, ['success', 'pending', 'failed'], true) ? $status : null;

        $perPage = (int) $request->integer('perPage');
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::PER_PAGE_OPTIONS[0];

        // IDs of all transactions that belong to any cross-border transfer
        // (funding, payout, or refund) or are refunds of those transactions.
        // We exclude these from standalone payouts to prevent double-counting or phantom payouts.
        $cbTransfers = CrossBorderTransfer::query()
            ->where('user_id', $user->id)
            ->get(['funding_transaction_id', 'payout_transaction_id', 'refund_transaction_id']);

        $cbTxIds = $cbTransfers
            ->flatMap(fn ($cb) => array_filter([
                $cb->funding_transaction_id,
                $cb->payout_transaction_id,
                $cb->refund_transaction_id,
            ]))
            ->unique()
            ->values();

        // Cross-border base query
        $cbBase = fn (): Builder => CrossBorderTransfer::query()
            ->where('user_id', $user->id)
            ->tap(fn (Builder $q) => $this->applyCrossBorderSearch($q, $search));

        // Standalone payout base query (debit transactions through user's providers)
        $payoutBase = fn (): Builder => Transaction::query()
            ->whereIn('payment_provider_id', $providerIds)
            ->where('direction', 'debit')
            ->whereNotIn('id', $cbTxIds)
            ->where(function (Builder $q) use ($cbTxIds) {
                $q->whereNull('refund_of_id')
                    ->orWhereNotIn('refund_of_id', $cbTxIds);
            })
            ->tap(fn (Builder $q) => $this->applyPayoutSearch($q, $search));

        $cbInWindow = fn (): Builder => $cbBase()->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from));
        $payoutInWindow = fn (): Builder => $payoutBase()->when($from, fn (Builder $q) => $q->where('created_at', '>=', $from));

        $metrics = $this->metrics($cbInWindow(), $payoutInWindow());
        $metrics['volumes'] = CurrencyVolumes::padded(
            $metrics['volumes'],
            $this->recentCurrencies($cbBase(), $payoutBase()),
            CurrencyVolumes::supportedBy($user->paymentProviders()->get()),
        );
        $metricTrend = $this->metricTrend($cbBase(), $payoutBase());
        $topCurrencies = $this->topCurrencies($cbInWindow(), $payoutInWindow());
        $topCountries = $this->topCountries($cbInWindow(), $payoutInWindow());
        $transfers = $this->paginatedTransfers($request, $cbInWindow(), $payoutInWindow(), $type, $status, $perPage);

        return Inertia::render('transfers/index', [
            'metrics' => $metrics,
            'metricTrend' => $metricTrend,
            'topCurrencies' => $topCurrencies,
            'topCountries' => $topCountries,
            'transfers' => $transfers,
            'filters' => [
                'range' => $range,
                'type' => $type,
                'status' => $status,
                'q' => $search ?: null,
                'perPage' => $perPage,
            ],
            'rangeOptions' => self::RANGES,
            'typeOptions' => self::TYPES,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    /**
     * Re-check a payout transaction's status.
     */
    public function verifyTransaction(Request $request, Transaction $transaction): RedirectResponse
    {
        $providerIds = $request->user()->paymentProviders()->pluck('id');
        abort_unless($providerIds->contains($transaction->payment_provider_id), 403);

        $response = $this->verifier->verify($transaction);
        $data = $response->getData(true);
        $status = $data['data']['status'] ?? $transaction->fresh()->status->value;

        return back()->with('message', "Transaction {$transaction->transaction_id} status: {$status}.");
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
     * Apply search to cross-border transfers.
     */
    private function applyCrossBorderSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';

        $query->where(function (Builder $inner) use ($like, $search): void {
            $inner->whereLike('reference', $like)
                ->orWhereLike('recipient_account', $like)
                ->orWhereLike('recipient_name', $like)
                ->orWhereLike('recipient_country', $like)
                ->orWhereLike('source_currency', $like)
                ->orWhereLike('target_currency', $like)
                ->orWhereLike('narration', $like)
                ->orWhereLike('status', $like)
                ->when(is_numeric($search), function (Builder $q) use ($search): void {
                    $minor = (int) round(((float) $search) * 100);
                    $q->orWhere('source_amount_minor', $minor)->orWhere('target_amount_minor', $minor);
                })
                ->orWhereHas('fundingProvider', fn (Builder $p) => $p->whereLike('name', $like))
                ->orWhereHas('payoutProvider', fn (Builder $p) => $p->whereLike('name', $like));
        });
    }

    /**
     * Apply search to direct payout transactions.
     */
    private function applyPayoutSearch(Builder $query, string $search): void
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
     * Compute headline metrics across cross-border transfers and payout transactions.
     *
     * @return array<string, mixed>
     */
    private function metrics(Builder $cbQuery, Builder $payoutQuery): array
    {
        $cbSuccessStatuses = [CrossBorderStatus::DELIVERED->value];
        // Before the counts below add their selects to the queries.
        $volumes = $this->volumes(clone $cbQuery, clone $payoutQuery, $cbSuccessStatuses);

        // Cross-border counts
        $cbPendingStatuses = [
            CrossBorderStatus::AWAITING_PAYMENT->value,
            CrossBorderStatus::FUNDED->value,
            CrossBorderStatus::PAYING_OUT->value,
            CrossBorderStatus::REFUNDING->value,
        ];
        $cbFailedStatuses = [
            CrossBorderStatus::PAYMENT_FAILED->value,
            CrossBorderStatus::PAYOUT_FAILED->value,
            CrossBorderStatus::REFUND_FAILED->value,
            CrossBorderStatus::REFUNDED->value,
        ];

        $cbRow = $cbQuery
            ->selectRaw('count(*) as total')
            ->selectRaw("coalesce(sum(case when status in ('".implode("','", $cbSuccessStatuses)."') then 1 else 0 end), 0) as success")
            ->selectRaw("coalesce(sum(case when status in ('".implode("','", $cbPendingStatuses)."') then 1 else 0 end), 0) as pending")
            ->selectRaw("coalesce(sum(case when status in ('".implode("','", $cbFailedStatuses)."') then 1 else 0 end), 0) as failed")
            ->first();

        // Payout counts
        $payoutRow = $payoutQuery
            ->selectRaw('count(*) as total')
            ->selectRaw("coalesce(sum(case when status = 'success' then 1 else 0 end), 0) as success")
            ->selectRaw("coalesce(sum(case when status = 'pending' then 1 else 0 end), 0) as pending")
            ->selectRaw("coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as failed")
            ->first();

        $cbTotal = (int) ($cbRow->total ?? 0);
        $payoutTotal = (int) ($payoutRow->total ?? 0);
        $total = $cbTotal + $payoutTotal;

        $cbSuccess = (int) ($cbRow->success ?? 0);
        $payoutSuccess = (int) ($payoutRow->success ?? 0);
        $success = $cbSuccess + $payoutSuccess;

        $cbPending = (int) ($cbRow->pending ?? 0);
        $payoutPending = (int) ($payoutRow->pending ?? 0);
        $pending = $cbPending + $payoutPending;

        $cbFailed = (int) ($cbRow->failed ?? 0);
        $payoutFailed = (int) ($payoutRow->failed ?? 0);
        $failed = $cbFailed + $payoutFailed;

        return [
            'total' => $total,
            'success' => $success,
            'pending' => $pending,
            'failed' => $failed,
            'volumes' => $volumes,
            'successRate' => $total > 0 ? round(($success / $total) * 100, 1) : null,
            'crossBorderCount' => $cbTotal,
            'payoutCount' => $payoutTotal,
        ];
    }

    /**
     * Currencies this account has sent in (transfer recipients and payouts),
     * most recently used first.
     *
     * @return list<string>
     */
    private function recentCurrencies(Builder $cbQuery, Builder $payoutQuery): array
    {
        $transfers = $cbQuery
            ->whereNotNull('target_currency')
            ->selectRaw('target_currency as currency, max(created_at) as last_used')
            ->groupBy('target_currency')
            ->get();

        $payouts = $payoutQuery
            ->whereNotNull('currency')
            ->selectRaw('currency, max(created_at) as last_used')
            ->groupBy('currency')
            ->get();

        return $transfers->concat($payouts)
            ->sortByDesc(fn ($row): string => (string) $row->last_used)
            ->pluck('currency')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Delivered volume in each currency, busiest first: what reached wallets,
     * in the recipient's currency, for transfers and payouts alike. Currencies
     * are never added together.
     *
     * @param  list<string>  $cbSuccessStatuses
     * @return list<array{currency: string, amount: float, count: int}>
     */
    private function volumes(Builder $cbQuery, Builder $payoutQuery, array $cbSuccessStatuses): array
    {
        $totals = [];

        $delivered = (clone $cbQuery)
            ->whereIn('status', $cbSuccessStatuses)
            ->whereNotNull('target_currency')
            ->selectRaw('target_currency as currency')
            ->selectRaw('count(*) as count')
            ->selectRaw('coalesce(sum(target_amount_minor), 0) as minor')
            ->groupBy('target_currency')
            ->get();

        foreach ($delivered as $row) {
            $currency = strtoupper((string) $row->currency);
            $totals[$currency] ??= ['currency' => $currency, 'amount' => 0.0, 'count' => 0];
            $totals[$currency]['amount'] += (int) $row->minor / 10 ** Currency::decimals($currency);
            $totals[$currency]['count'] += (int) $row->count;
        }

        $paidOut = (clone $payoutQuery)
            ->where('status', 'success')
            ->whereNotNull('currency')
            ->selectRaw('currency')
            ->selectRaw('count(*) as count')
            ->selectRaw('coalesce(sum(amount), 0) as amount')
            ->groupBy('currency')
            ->get();

        foreach ($paidOut as $row) {
            $currency = strtoupper((string) $row->currency);
            $totals[$currency] ??= ['currency' => $currency, 'amount' => 0.0, 'count' => 0];
            $totals[$currency]['amount'] += (float) $row->amount;
            $totals[$currency]['count'] += (int) $row->count;
        }

        return collect($totals)
            ->map(fn (array $total): array => [...$total, 'amount' => round($total['amount'], Currency::decimals($total['currency']))])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Daily per-status counts over the last 14 days for the sparklines.
     *
     * @return array{total: list<int>, success: list<int>, pending: list<int>, failed: list<int>}
     */
    private function metricTrend(Builder $cbQuery, Builder $payoutQuery): array
    {
        $days = 14;
        $start = Carbon::now()->subDays($days - 1)->startOfDay();

        $cbSuccessStatuses = [CrossBorderStatus::DELIVERED->value];
        $cbPendingStatuses = [
            CrossBorderStatus::AWAITING_PAYMENT->value,
            CrossBorderStatus::FUNDED->value,
            CrossBorderStatus::PAYING_OUT->value,
            CrossBorderStatus::REFUNDING->value,
        ];
        $cbFailedStatuses = [
            CrossBorderStatus::PAYMENT_FAILED->value,
            CrossBorderStatus::PAYOUT_FAILED->value,
            CrossBorderStatus::REFUND_FAILED->value,
            CrossBorderStatus::REFUNDED->value,
        ];

        $cbRows = $cbQuery
            ->where('created_at', '>=', $start)
            ->selectRaw(SqlDate::bucket($cbQuery).' as bucket')
            ->selectRaw('count(*) as total')
            ->selectRaw("coalesce(sum(case when status in ('".implode("','", $cbSuccessStatuses)."') then 1 else 0 end), 0) as success")
            ->selectRaw("coalesce(sum(case when status in ('".implode("','", $cbPendingStatuses)."') then 1 else 0 end), 0) as pending")
            ->selectRaw("coalesce(sum(case when status in ('".implode("','", $cbFailedStatuses)."') then 1 else 0 end), 0) as failed")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $payoutRows = $payoutQuery
            ->where('created_at', '>=', $start)
            ->selectRaw(SqlDate::bucket($payoutQuery).' as bucket')
            ->selectRaw('count(*) as total')
            ->selectRaw("coalesce(sum(case when status = 'success' then 1 else 0 end), 0) as success")
            ->selectRaw("coalesce(sum(case when status = 'pending' then 1 else 0 end), 0) as pending")
            ->selectRaw("coalesce(sum(case when status = 'failed' then 1 else 0 end), 0) as failed")
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $series = ['total' => [], 'success' => [], 'pending' => [], 'failed' => []];

        for ($i = 0; $i < $days; $i++) {
            $key = $start->copy()->addDays($i)->format('Y-m-d');
            $cb = $cbRows->get($key);
            $po = $payoutRows->get($key);

            $series['total'][] = (int) (($cb->total ?? 0) + ($po->total ?? 0));
            $series['success'][] = (int) (($cb->success ?? 0) + ($po->success ?? 0));
            $series['pending'][] = (int) (($cb->pending ?? 0) + ($po->pending ?? 0));
            $series['failed'][] = (int) (($cb->failed ?? 0) + ($po->failed ?? 0));
        }

        return $series;
    }

    /**
     * Top destination currencies by count and volume.
     *
     * @return list<array{currency: string, count: int, volume: float}>
     */
    private function topCurrencies(Builder $cbQuery, Builder $payoutQuery): array
    {
        $cbSuccessStatuses = [CrossBorderStatus::DELIVERED->value];

        $cbCurrencies = $cbQuery
            ->selectRaw('target_currency as currency')
            ->selectRaw('count(*) as count')
            ->selectRaw("coalesce(sum(case when status in ('".implode("','", $cbSuccessStatuses)."') then target_amount_minor / 100.0 else 0 end), 0) as volume")
            ->whereNotNull('target_currency')
            ->groupBy('target_currency')
            ->get();

        $payoutCurrencies = $payoutQuery
            ->selectRaw('currency')
            ->selectRaw('count(*) as count')
            ->selectRaw("coalesce(sum(case when status = 'success' then amount else 0 end), 0) as volume")
            ->whereNotNull('currency')
            ->groupBy('currency')
            ->get();

        $merged = [];
        foreach ($cbCurrencies as $row) {
            $c = (string) $row->currency;
            $merged[$c] = [
                'currency' => $c,
                'count' => (int) $row->count,
                'volume' => (float) $row->volume,
            ];
        }

        foreach ($payoutCurrencies as $row) {
            $c = (string) $row->currency;
            if (isset($merged[$c])) {
                $merged[$c]['count'] += (int) $row->count;
                $merged[$c]['volume'] += (float) $row->volume;
            } else {
                $merged[$c] = [
                    'currency' => $c,
                    'count' => (int) $row->count,
                    'volume' => (float) $row->volume,
                ];
            }
        }

        usort($merged, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_slice(array_map(fn ($item): array => [
            'currency' => $item['currency'],
            'count' => $item['count'],
            'volume' => round($item['volume'], 2),
        ], $merged), 0, 6);
    }

    /**
     * Top recipient countries by count.
     *
     * @return list<array{code: string, name: string, count: int}>
     */
    private function topCountries(Builder $cbQuery, Builder $payoutQuery): array
    {
        $cbCountries = $cbQuery
            ->selectRaw('recipient_country as country')
            ->selectRaw('count(*) as count')
            ->whereNotNull('recipient_country')
            ->groupBy('recipient_country')
            ->get();

        $payoutCountries = $payoutQuery
            ->selectRaw('country')
            ->selectRaw('count(*) as count')
            ->whereNotNull('country')
            ->groupBy('country')
            ->get();

        $merged = [];
        foreach ($cbCountries as $row) {
            $c = (string) $row->country;
            $merged[$c] = (int) $row->count;
        }

        foreach ($payoutCountries as $row) {
            $c = (string) $row->country;
            $merged[$c] = ($merged[$c] ?? 0) + (int) $row->count;
        }

        arsort($merged);

        $result = [];
        foreach (array_slice($merged, 0, 6, true) as $code => $count) {
            $result[] = [
                'code' => $code,
                'name' => Market::name($code),
                'count' => $count,
            ];
        }

        return $result;
    }

    /**
     * Paginate normalized transfers based on the selected type and status filters.
     */
    private function paginatedTransfers(
        Request $request,
        Builder $cbQuery,
        Builder $payoutQuery,
        string $type,
        ?string $status,
        int $perPage
    ): LengthAwarePaginator {
        $includeCb = in_array($type, ['all', 'cross_border'], true);
        $includePayout = in_array($type, ['all', 'payout'], true);

        // Apply status scoping to Cross-Border query
        if ($status) {
            $cbQuery->whereIn('status', match ($status) {
                'success' => [CrossBorderStatus::DELIVERED],
                'pending' => [
                    CrossBorderStatus::AWAITING_PAYMENT,
                    CrossBorderStatus::FUNDED,
                    CrossBorderStatus::PAYING_OUT,
                    CrossBorderStatus::REFUNDING,
                ],
                'failed' => [
                    CrossBorderStatus::PAYMENT_FAILED,
                    CrossBorderStatus::PAYOUT_FAILED,
                    CrossBorderStatus::REFUND_FAILED,
                    CrossBorderStatus::REFUNDED,
                ],
                default => [],
            });
        }

        // Apply status scoping to Payout query
        if ($status) {
            $payoutQuery->where('status', match ($status) {
                'success' => TransactionStatus::SUCCESS,
                'pending' => TransactionStatus::PENDING,
                'failed' => TransactionStatus::FAILED,
                default => null,
            });
        }

        $items = new Collection;

        if ($includeCb) {
            $cbTransfers = $cbQuery
                ->with([
                    'fundingProvider:id,name,class,logo_url',
                    'payoutProvider:id,name,class,logo_url',
                    'fundingTransaction:id,transaction_id,amount,currency,status,provider_transaction_id,provider_response',
                    'payoutTransaction:id,transaction_id,amount,currency,status,provider_transaction_id,provider_response',
                    'refundTransaction:id,transaction_id,amount,currency,status,provider_transaction_id,provider_response',
                    'events',
                ])
                ->latest()
                ->get()
                ->map(fn (CrossBorderTransfer $transfer) => $this->transformCrossBorder($transfer));

            $items = $items->concat($cbTransfers);
        }

        if ($includePayout) {
            $payoutTransactions = $payoutQuery
                ->with([
                    'paymentProvider:id,name,class,logo_url',
                    'customer:id,name,email',
                    'customer.accounts:id,customer_id,number,country',
                ])
                ->latest()
                ->get()
                ->map(fn (Transaction $tx) => $this->transformPayout($tx));

            $items = $items->concat($payoutTransactions);
        }

        // Sort unified collection by created_at desc
        $sorted = $items->sortByDesc('rawCreatedAt')->values();

        $currentPage = ConcretePaginator::resolveCurrentPage();
        $total = $sorted->count();
        $slice = $sorted->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return (new ConcretePaginator($slice, $total, $perPage, $currentPage, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]))->withQueryString();
    }

    /**
     * Transform a cross-border transfer into a standardized transfer record.
     *
     * @return array<string, mixed>
     */
    private function transformCrossBorder(CrossBorderTransfer $transfer): array
    {
        $statusKey = match ($transfer->status) {
            CrossBorderStatus::DELIVERED => 'success',
            CrossBorderStatus::REFUNDED => 'refunded',
            CrossBorderStatus::AWAITING_PAYMENT,
            CrossBorderStatus::FUNDED,
            CrossBorderStatus::PAYING_OUT,
            CrossBorderStatus::REFUNDING => 'pending',
            default => 'failed',
        };

        $sourceAmount = $transfer->sourceAmount()->toFloat();
        $targetAmount = $transfer->targetAmount()->toFloat();

        // Extract provider feedback from payout or refund legs if present
        $payoutResponse = $transfer->payoutTransaction?->provider_response;
        $providerFeedback = null;
        if (is_array($payoutResponse)) {
            $providerFeedback = $payoutResponse['message']
                ?? $payoutResponse['error']
                ?? $payoutResponse['rejectionReason']
                ?? $payoutResponse['description']
                ?? (isset($payoutResponse['data']) && is_array($payoutResponse['data']) ? ($payoutResponse['data']['message'] ?? $payoutResponse['data']['error'] ?? null) : null);
        }

        // Format timeline events
        $events = $transfer->events->map(fn (CrossBorderTransferEvent $e): array => [
            'id' => $e->id,
            'from' => $e->from_status?->value,
            'to' => $e->to_status?->value,
            'fromLabel' => $e->from_status?->label(),
            'toLabel' => $e->to_status?->label(),
            'note' => $e->note,
            'createdAt' => $e->created_at?->format('Y-m-d H:i:s T'),
            'createdAtHuman' => $e->created_at?->diffForHumans(),
        ])->values()->all();

        return [
            'id' => "cb_{$transfer->id}",
            'type' => 'cross_border',
            'typeLabel' => 'Cross-Border',
            'reference' => $transfer->reference,
            'providerReference' => $transfer->payoutTransaction?->provider_transaction_id ?? $transfer->fundingTransaction?->provider_transaction_id,
            'status' => $statusKey,
            'statusLabel' => $transfer->status->label(),
            'rawStatus' => $transfer->status->value,
            'origin' => $transfer->origin,
            'amount' => $targetAmount,
            'currency' => $transfer->target_currency,
            'sourceAmount' => $sourceAmount,
            'sourceCurrency' => $transfer->source_currency,
            'targetAmount' => $targetAmount,
            'targetCurrency' => $transfer->target_currency,
            'country' => $transfer->recipient_country,
            'countryName' => $transfer->recipient_country ? Market::name($transfer->recipient_country) : null,
            'account' => $transfer->recipient_account,
            'recipientName' => $transfer->recipient_name,
            'network' => $transfer->recipient_network,
            'corridor' => "{$transfer->source_currency} → {$transfer->target_currency}".($transfer->recipient_country ? " ({$transfer->recipient_country})" : ''),
            'provider' => $transfer->payoutProvider?->name ?? $transfer->fundingProvider?->name,
            'providerLogo' => $transfer->payoutProvider?->logo_url ?? $transfer->fundingProvider?->logo_url,
            'fundingProvider' => $transfer->fundingProvider?->name,
            'payoutProvider' => $transfer->payoutProvider?->name,
            'fxRate' => $transfer->fx_rate !== null ? (string) $transfer->fx_rate : null,
            'fxMargin' => $transfer->fx_margin_percent !== null ? (string) $transfer->fx_margin_percent : null,
            'narration' => $transfer->narration,
            'failureReason' => $transfer->failure_reason,
            'providerFeedback' => $providerFeedback,
            'fundingLeg' => $transfer->fundingTransaction ? [
                'id' => $transfer->fundingTransaction->transaction_id,
                'providerReference' => $transfer->fundingTransaction->provider_transaction_id,
                'status' => $transfer->fundingTransaction->status->value,
                'amount' => (float) $transfer->fundingTransaction->amount,
                'currency' => $transfer->fundingTransaction->currency,
                'provider' => $transfer->fundingProvider?->name,
            ] : null,
            'payoutLeg' => $transfer->payoutTransaction ? [
                'id' => $transfer->payoutTransaction->transaction_id,
                'providerReference' => $transfer->payoutTransaction->provider_transaction_id,
                'status' => $transfer->payoutTransaction->status->value,
                'amount' => (float) $transfer->payoutTransaction->amount,
                'currency' => $transfer->payoutTransaction->currency,
                'provider' => $transfer->payoutProvider?->name,
                'providerResponse' => $transfer->payoutTransaction->provider_response,
            ] : null,
            'refundLeg' => $transfer->refundTransaction ? [
                'id' => $transfer->refundTransaction->transaction_id,
                'providerReference' => $transfer->refundTransaction->provider_transaction_id,
                'status' => $transfer->refundTransaction->status->value,
                'amount' => (float) $transfer->refundTransaction->amount,
                'currency' => $transfer->refundTransaction->currency,
                'provider' => $transfer->fundingProvider?->name,
                'providerResponse' => $transfer->refundTransaction->provider_response,
            ] : null,
            'logsUrl' => route('logs.index', ['q' => $transfer->reference]),
            'events' => $events,
            'fundedAt' => $transfer->funded_at?->format('Y-m-d H:i:s T'),
            'deliveredAt' => $transfer->delivered_at?->format('Y-m-d H:i:s T'),
            'refundedAt' => $transfer->refunded_at?->format('Y-m-d H:i:s T'),
            'createdAtHuman' => $transfer->created_at?->diffForHumans(),
            'createdAt' => $transfer->created_at?->format('Y-m-d H:i:s T'),
            'rawCreatedAt' => $transfer->created_at?->timestamp ?? 0,
        ];
    }

    /**
     * Transform a direct payout transaction into a standardized transfer record.
     *
     * @return array<string, mixed>
     */
    private function transformPayout(Transaction $tx): array
    {
        $statusKey = $tx->status->value;
        $statusLabel = match ($tx->status) {
            TransactionStatus::SUCCESS => 'Paid out',
            TransactionStatus::PENDING => 'Processing',
            TransactionStatus::FAILED => 'Failed',
        };

        $amount = round((float) $tx->amount, 2);

        $payoutResponse = $tx->provider_response;
        $providerFeedback = null;
        if (is_array($payoutResponse)) {
            $providerFeedback = $payoutResponse['message']
                ?? $payoutResponse['error']
                ?? $payoutResponse['rejectionReason']
                ?? $payoutResponse['description']
                ?? (isset($payoutResponse['data']) && is_array($payoutResponse['data']) ? ($payoutResponse['data']['message'] ?? $payoutResponse['data']['error'] ?? null) : null);
        }

        return [
            'id' => "tx_{$tx->id}",
            'type' => 'payout',
            'typeLabel' => 'Direct Payout',
            'reference' => $tx->transaction_id,
            'providerReference' => $tx->provider_transaction_id,
            'status' => $statusKey,
            'statusLabel' => $statusLabel,
            'rawStatus' => $tx->status->value,
            'origin' => 'api',
            'amount' => $amount,
            'currency' => $tx->currency,
            'sourceAmount' => null,
            'sourceCurrency' => null,
            'targetAmount' => $amount,
            'targetCurrency' => $tx->currency,
            'country' => $tx->country,
            'countryName' => $tx->country ? Market::name($tx->country) : null,
            'account' => $this->payerAccount($tx),
            'recipientName' => $tx->customer?->name ?? $tx->customer?->email,
            'network' => null,
            'corridor' => "{$tx->currency}".($tx->country ? " ({$tx->country})" : ''),
            'provider' => $tx->paymentProvider?->name,
            'providerLogo' => $tx->paymentProvider?->logo_url,
            'fundingProvider' => null,
            'payoutProvider' => $tx->paymentProvider?->name,
            'fxRate' => $tx->is_fx && $tx->fx_rate !== null ? (string) $tx->fx_rate : null,
            'fxMargin' => null,
            'narration' => null,
            'failureReason' => $tx->status === TransactionStatus::FAILED ? ($providerFeedback ?? 'Payout declined') : null,
            'providerFeedback' => $providerFeedback,
            'fundingLeg' => null,
            'payoutLeg' => [
                'id' => $tx->transaction_id,
                'providerReference' => $tx->provider_transaction_id,
                'status' => $tx->status->value,
                'amount' => $amount,
                'currency' => $tx->currency,
                'provider' => $tx->paymentProvider?->name,
                'providerResponse' => $tx->provider_response,
            ],
            'refundLeg' => null,
            'logsUrl' => route('logs.index', ['q' => $tx->transaction_id]),
            'events' => [],
            'fees' => $this->feeBreakdown($tx),
            'fundedAt' => null,
            'deliveredAt' => $tx->status === TransactionStatus::SUCCESS ? $tx->updated_at?->format('Y-m-d H:i:s T') : null,
            'refundedAt' => null,
            'createdAtHuman' => $tx->created_at?->diffForHumans(),
            'createdAt' => $tx->created_at?->format('Y-m-d H:i:s T'),
            'rawCreatedAt' => $tx->created_at?->timestamp ?? 0,
        ];
    }

    /**
     * The transaction's fees and what was deducted from float.
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
     * The recipient/payer account number for a transaction.
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
