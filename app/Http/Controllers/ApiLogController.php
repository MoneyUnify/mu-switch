<?php

namespace App\Http\Controllers;

use App\Models\ApiLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ApiLogController extends Controller
{
    /**
     * Searchable fields exposed in the UI.
     *
     * @var list<string>
     */
    private const SEARCH_FIELDS = ['all', 'path', 'ip', 'method', 'status', 'exception'];

    /**
     * Selectable date ranges.
     *
     * @var list<string>
     */
    private const RANGES = ['today', 'yesterday', 'month', 'custom'];

    /**
     * Display a paginated, searchable, filterable list of the user's API logs
     * with a status summary and a status-code timeline.
     */
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $range = $request->string('range')->toString();
        $range = in_array($range, self::RANGES, true) ? $range : 'month';
        [$from, $to, $grain] = $this->resolveRange($range, $request);

        $search = trim($request->string('q')->toString());
        $field = $request->string('field')->toString();
        $field = in_array($field, self::SEARCH_FIELDS, true) ? $field : 'all';

        $status = $request->string('status')->toString();
        $status = in_array($status, ['success', 'client', 'server'], true) ? $status : null;

        // Base query: the user's logs within the selected window, matching search.
        $scoped = fn (): Builder => ApiLog::query()
            ->where('user_id', $userId)
            ->whereBetween('created_at', [$from, $to])
            ->tap(fn (Builder $q) => $this->applySearch($q, $field, $search));

        return Inertia::render('logs/index', [
            'logs' => $this->paginatedLogs($scoped(), $status),
            'stats' => $this->stats($scoped()),
            'chart' => $this->chartSeries($scoped(), $from, $to, $grain),
            'filters' => [
                'status' => $status,
                'range' => $range,
                'q' => $search ?: null,
                'field' => $field,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'fieldOptions' => self::SEARCH_FIELDS,
        ]);
    }

    /**
     * Resolve a range key into a [from, to, grain] tuple.
     *
     * @return array{0: Carbon, 1: Carbon, 2: 'hour'|'day'}
     */
    private function resolveRange(string $range, Request $request): array
    {
        $now = Carbon::now();

        return match ($range) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'hour'],
            'yesterday' => [$now->copy()->subDay()->startOfDay(), $now->copy()->subDay()->endOfDay(), 'hour'],
            'custom' => $this->resolveCustomRange($request, $now),
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'day'],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: 'hour'|'day'}
     */
    private function resolveCustomRange(Request $request, Carbon $now): array
    {
        $from = ($this->parseDate($request->input('from')) ?? $now->copy()->startOfMonth())->startOfDay();
        $to = ($this->parseDate($request->input('to')) ?? $now->copy())->endOfDay();

        if ($to->lessThan($from)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to, $from->diffInDays($to) <= 2 ? 'hour' : 'day'];
    }

    /**
     * Safely parse a user-supplied date into a mutable Carbon instance.
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Apply the search term to the given query for the selected field.
     */
    private function applySearch(Builder $query, string $field, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';

        match ($field) {
            'path' => $query->where('url', 'like', $like),
            'ip' => $query->where('ip_address', 'like', $like),
            'method' => $query->where('method', strtoupper($search)),
            'status' => $query->where('response_status', (int) $search),
            'exception' => $query->where(fn (Builder $q) => $q
                ->where('exception_class', 'like', $like)
                ->orWhere('exception_message', 'like', $like)),
            default => $query->where(fn (Builder $q) => $q
                ->where('url', 'like', $like)
                ->orWhere('ip_address', 'like', $like)
                ->orWhere('method', 'like', $like)
                ->orWhere('exception_message', 'like', $like)
                ->when(is_numeric($search), fn (Builder $q2) => $q2->orWhere('response_status', (int) $search))),
        };
    }

    /**
     * @return array<string, int>
     */
    private function stats(Builder $query): array
    {
        return [
            'total' => (clone $query)->count(),
            'success' => (clone $query)->whereBetween('response_status', [200, 299])->count(),
            'clientError' => (clone $query)->whereBetween('response_status', [400, 499])->count(),
            'serverError' => (clone $query)->whereBetween('response_status', [500, 599])->count(),
        ];
    }

    /**
     * Paginate the logs, optionally narrowed to a status class.
     */
    private function paginatedLogs(Builder $query, ?string $status): LengthAwarePaginator
    {
        return $query
            ->when($status === 'success', fn (Builder $q) => $q->whereBetween('response_status', [200, 299]))
            ->when($status === 'client', fn (Builder $q) => $q->whereBetween('response_status', [400, 499]))
            ->when($status === 'server', fn (Builder $q) => $q->whereBetween('response_status', [500, 599]))
            ->latest('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (ApiLog $log): array => [
                'id' => $log->id,
                'method' => $log->method,
                'path' => parse_url((string) $log->url, PHP_URL_PATH) ?: $log->url,
                'url' => $log->url,
                'route' => $log->route,
                'status' => $log->response_status,
                'durationMs' => $log->duration_ms,
                'ipAddress' => $log->ip_address,
                'userAgent' => $log->user_agent,
                'hasException' => $log->exception_class !== null,
                'exceptionClass' => $log->exception_class,
                'exceptionMessage' => $log->exception_message,
                'exceptionTrace' => $log->exception_trace,
                'requestHeaders' => $log->request_headers,
                'requestBody' => $log->request_body,
                'responseBody' => $log->response_body,
                'createdAtHuman' => $log->created_at?->diffForHumans(),
                'createdAt' => $log->created_at?->format('Y-m-d H:i:s T'),
            ]);
    }

    /**
     * Build a time-bucketed status-code timeline for the area chart.
     *
     * @return array{points: list<array<string, mixed>>, grain: string}
     */
    private function chartSeries(Builder $query, Carbon $from, Carbon $to, string $grain): array
    {
        $bucketExpr = $grain === 'hour'
            ? "strftime('%Y-%m-%d %H:00:00', created_at)"
            : "strftime('%Y-%m-%d', created_at)";

        $rows = $query
            ->selectRaw("{$bucketExpr} as bucket")
            ->selectRaw('sum(case when response_status between 200 and 299 then 1 else 0 end) as s2')
            ->selectRaw('sum(case when response_status between 300 and 399 then 1 else 0 end) as s3')
            ->selectRaw('sum(case when response_status between 400 and 499 then 1 else 0 end) as s4')
            ->selectRaw('sum(case when response_status between 500 and 599 then 1 else 0 end) as s5')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $points = [];
        $cursor = $from->copy();
        $step = $grain === 'hour' ? '1 hour' : '1 day';
        $guard = 0;

        while ($cursor->lessThanOrEqualTo($to) && $guard++ < 1000) {
            $key = $grain === 'hour' ? $cursor->format('Y-m-d H:00:00') : $cursor->format('Y-m-d');
            $row = $rows->get($key);

            $points[] = [
                'label' => $grain === 'hour' ? $cursor->format('H:i') : $cursor->format('M j'),
                's2xx' => (int) ($row->s2 ?? 0),
                's3xx' => (int) ($row->s3 ?? 0),
                's4xx' => (int) ($row->s4 ?? 0),
                's5xx' => (int) ($row->s5 ?? 0),
            ];

            $cursor->add($step);
        }

        return ['points' => $points, 'grain' => $grain];
    }
}
