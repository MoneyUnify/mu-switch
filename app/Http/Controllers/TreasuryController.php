<?php

namespace App\Http\Controllers;

use App\Enums\LedgerAccount;
use App\Enums\TransferStatus;
use App\Models\FloatAlert;
use App\Models\FloatRule;
use App\Models\LedgerEntry;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\TreasuryTransfer;
use App\Models\TreasuryTransferEvent;
use App\Models\User;
use App\Support\Ai\AiProviderChain;
use App\Support\Currency;
use App\Support\Ledger\LedgerBalance;
use App\Support\Market;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One view of the money across the account's provider accounts: balances,
 * float alerts, transfers between accounts and recent ledger activity.
 */
class TreasuryController extends Controller
{
    /**
     * Each table's search, page and page-size query keys, and its page sizes
     * (the first is the default).
     *
     * @var array<string, array{search: string, page: string, perPage: string, options: list<int>}>
     */
    private const TABLES = [
        'accounts' => ['search' => 'aq', 'page' => 'accounts', 'perPage' => 'accountsPer', 'options' => [5, 10, 25, 50]],
        'transfers' => ['search' => 'tq', 'page' => 'transfers', 'perPage' => 'transfersPer', 'options' => [5, 10, 25, 50]],
        'entries' => ['search' => 'lq', 'page' => 'entries', 'perPage' => 'entriesPer', 'options' => [10, 25, 50]],
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $providers = $user->paymentProviders()->orderBy('name')->get();
        $rules = FloatRule::query()->whereIn('payment_provider_id', $providers->modelKeys())->with('openAlert')->get();

        return Inertia::render('treasury/index', [
            'accounts' => $this->accounts($request, $providers, $rules),
            'alerts' => $this->openAlerts($rules),
            'transfers' => $this->transfers($request, $user),
            'entries' => $this->recentEntries($request, $user),
            'filters' => [
                'accounts' => $this->search($request, 'accounts') ?: null,
                'transfers' => $this->search($request, 'transfers') ?: null,
                'entries' => $this->search($request, 'entries') ?: null,
            ],
            'aiReady' => AiProviderChain::for($user)->canRun(),
            'perPageOptions' => array_map(fn (array $table): array => $table['options'], self::TABLES),
            'providers' => $providers->map(fn (PaymentProvider $provider): array => [
                'id' => $provider->id,
                'name' => $provider->name,
                'currencies' => $this->currenciesFor($provider),
            ])->values(),
        ]);
    }

    /**
     * Every provider account (provider and currency) with money or a float rule.
     *
     * @param  Collection<int, PaymentProvider>  $providers
     * @param  Collection<int, FloatRule>  $rules
     */
    private function accounts(Request $request, Collection $providers, Collection $rules): LengthAwarePaginator
    {
        $summaries = LedgerBalance::summaries($providers->modelKeys());
        $inTransit = TreasuryTransfer::query()
            ->whereIn('from_provider_id', $providers->modelKeys())
            ->where('status', TransferStatus::IN_TRANSIT->value)
            ->selectRaw('from_provider_id, currency, sum(amount_minor) as minor')
            ->groupBy('from_provider_id', 'currency')
            ->get()
            ->keyBy(fn ($row) => $row->from_provider_id.'|'.$row->currency);

        $accounts = [];

        foreach ($providers as $provider) {
            $currencies = collect($summaries->get($provider->id, []))->pluck('currency')
                ->merge($rules->where('payment_provider_id', $provider->id)->pluck('currency'))
                ->unique();

            foreach ($currencies as $currency) {
                $summary = collect($summaries->get($provider->id, []))->firstWhere('currency', $currency);
                $rule = $rules->first(fn (FloatRule $rule): bool => $rule->payment_provider_id === $provider->id && $rule->currency === $currency);
                $transit = (int) ($inTransit->get($provider->id.'|'.$currency)->minor ?? 0);

                $accounts[] = [
                    'providerId' => $provider->id,
                    'provider' => $provider->name,
                    'logo' => $provider->logo_url,
                    'currency' => $currency,
                    'balance' => $this->money(Currency::toMinorUnits((string) ($summary['net'] ?? 0), $currency), $currency),
                    'inTransitOut' => $transit > 0 ? $this->money($transit, $currency) : null,
                    'estimated' => (bool) ($summary['estimated'] ?? false),
                    'rule' => $rule ? [
                        'id' => $rule->id,
                        'minimum' => $this->money($rule->minimum_minor, $currency),
                        'target' => $rule->target_minor === null ? null : $this->money($rule->target_minor, $currency),
                        'active' => $rule->is_active,
                    ] : null,
                    'status' => match (true) {
                        ! $rule || ! $rule->is_active => 'none',
                        $rule->openAlert !== null => 'low',
                        default => 'ok',
                    },
                ];
            }
        }

        $search = mb_strtolower($this->search($request, 'accounts'));
        $statusLabels = ['ok' => 'healthy', 'low' => 'below minimum low', 'none' => 'no float rule'];

        $matches = collect($accounts)->filter(fn (array $account): bool => $search === ''
            || str_contains(mb_strtolower($account['provider'].' '.$account['currency'].' '.$statusLabels[$account['status']]), $search));

        return $this->paginateCollection($matches, $request, 'accounts');
    }

    /**
     * @param  Collection<int, FloatRule>  $rules
     * @return list<array<string, mixed>>
     */
    private function openAlerts(Collection $rules): array
    {
        return $rules
            ->filter(fn (FloatRule $rule): bool => $rule->openAlert instanceof FloatAlert)
            ->map(fn (FloatRule $rule): array => [
                'id' => $rule->openAlert->id,
                'providerId' => $rule->payment_provider_id,
                'provider' => $rule->paymentProvider->name,
                'currency' => $rule->currency,
                'balance' => $this->money($rule->openAlert->balance_minor, $rule->currency),
                'minimum' => $this->money($rule->minimum_minor, $rule->currency),
                'target' => $rule->target_minor === null ? null : $this->money($rule->target_minor, $rule->currency),
                'openedAt' => $rule->openAlert->opened_at?->diffForHumans(),
            ])
            ->values()
            ->all();
    }

    /**
     * The account's transfers, newest first, searchable and paginated.
     */
    private function transfers(Request $request, User $user): LengthAwarePaginator
    {
        $search = $this->search($request, 'transfers');
        $like = '%'.$search.'%';

        return TreasuryTransfer::query()
            ->where('user_id', $user->id)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereLike('reference', $like)
                ->orWhereLike('status', '%'.str_replace(' ', '_', mb_strtolower($search)).'%')
                ->orWhereLike('currency', $like)
                ->orWhereLike('external_reference', $like)
                ->orWhereLike('note', $like)
                ->orWhereLike('reasoning', $like)
                ->orWhereHas('fromProvider', fn (Builder $p) => $p->whereLike('name', $like))
                ->orWhereHas('toProvider', fn (Builder $p) => $p->whereLike('name', $like))))
            ->with(['fromProvider:id,name', 'toProvider:id,name', 'events.user:id,name'])
            ->latest('id')
            ->paginate($this->perPage($request, 'transfers'), ['*'], self::TABLES['transfers']['page'])
            ->withQueryString()
            ->through(fn (TreasuryTransfer $transfer): array => [
                'id' => $transfer->id,
                'reference' => $transfer->reference,
                'from' => $transfer->fromProvider?->name,
                'to' => $transfer->toProvider?->name,
                'currency' => $transfer->currency,
                'toCurrency' => $transfer->destinationCurrency(),
                'isFx' => $transfer->isFx(),
                'fxRate' => $transfer->fx_rate ? (string) $transfer->fx_rate : null,
                'amount' => $this->money($transfer->amount_minor, $transfer->currency),
                'targetAmount' => $transfer->isFx() ? $this->money($transfer->target_amount_minor ?? $transfer->amount_minor, $transfer->destinationCurrency()) : null,
                'fee' => $this->money($transfer->fee_minor, $transfer->currency),
                'received' => $transfer->received_minor === null ? null : $this->money($transfer->received_minor, $transfer->destinationCurrency()),
                'status' => $transfer->status->value,
                'externalReference' => $transfer->external_reference,
                'note' => $transfer->note,
                'origin' => $transfer->origin,
                'reasoning' => $transfer->reasoning,
                'failureReason' => $transfer->failure_reason,
                'createdAt' => $transfer->created_at?->diffForHumans(),
                'events' => $transfer->events->map(fn (TreasuryTransferEvent $event): array => [
                    'to' => $event->to_status->value,
                    'by' => $event->user?->name,
                    'note' => $event->note,
                    'at' => $event->created_at?->format('Y-m-d H:i'),
                ])->all(),
            ]);
    }

    /**
     * Movements in the account's provider accounts, newest first, searchable and paginated.
     */
    private function recentEntries(Request $request, User $user): LengthAwarePaginator
    {
        $search = $this->search($request, 'entries');
        $like = '%'.$search.'%';
        $type = '%'.str_replace(' ', '_', mb_strtolower($search)).'%';

        return LedgerEntry::query()
            ->where('user_id', $user->id)
            ->where('account', LedgerAccount::PROVIDER->value)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->whereLike('type', $type)
                ->orWhereLike('currency', $like)
                ->orWhereHas('paymentProvider', fn (Builder $p) => $p->whereLike('name', $like))
                ->orWhereHasMorph('source', [Transaction::class], fn (Builder $t) => $t->whereLike('transaction_id', $like))
                ->orWhereHasMorph('source', [TreasuryTransfer::class], fn (Builder $t) => $t->whereLike('reference', $like))))
            ->with(['paymentProvider:id,name', 'source'])
            ->latest('occurred_at')
            ->latest('id')
            ->paginate($this->perPage($request, 'entries'), ['*'], self::TABLES['entries']['page'])
            ->withQueryString()
            ->through(fn (LedgerEntry $entry): array => [
                'id' => $entry->id,
                'type' => $entry->type->value,
                'provider' => $entry->paymentProvider?->name,
                'currency' => $entry->currency,
                'amount' => $this->money($entry->amount_minor, $entry->currency),
                'estimated' => $entry->estimated,
                'reference' => match (true) {
                    $entry->source instanceof Transaction => $entry->source->transaction_id,
                    $entry->source instanceof TreasuryTransfer => $entry->source->reference,
                    default => $entry->meta['note'] ?? null,
                },
                'occurredAt' => $entry->occurred_at?->diffForHumans(),
            ]);
    }

    /**
     * Currencies a provider account can hold: its markets' currencies plus any
     * it declares (e.g. PayPal's).
     *
     * @return list<string>
     */
    private function currenciesFor(PaymentProvider $provider): array
    {
        $countries = (array) ($provider->config['supported_countries'] ?? []);
        $currencies = array_map(fn (string $country): ?string => Market::currency($country), $countries);

        if (defined($provider->class.'::SUPPORTED_CURRENCIES')) {
            $currencies = [...$currencies, ...$provider->class::SUPPORTED_CURRENCIES];
        }

        $currencies = array_values(array_unique(array_filter($currencies)));
        sort($currencies);

        return $currencies;
    }

    /**
     * A table's trimmed search term.
     */
    private function search(Request $request, string $table): string
    {
        return trim($request->string(self::TABLES[$table]['search'])->toString());
    }

    /**
     * A table's requested page size, limited to its options.
     */
    private function perPage(Request $request, string $table): int
    {
        $options = self::TABLES[$table]['options'];
        $perPage = (int) $request->integer(self::TABLES[$table]['perPage']);

        return in_array($perPage, $options, true) ? $perPage : $options[0];
    }

    /**
     * Paginate rows built in memory, keeping the other tables' query string.
     *
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function paginateCollection(Collection $items, Request $request, string $table): LengthAwarePaginator
    {
        $perPage = $this->perPage($request, $table);
        $pageName = self::TABLES[$table]['page'];
        $page = Paginator::resolveCurrentPage($pageName);

        return (new Paginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]))->withQueryString();
    }

    /**
     * Minor units as an exact decimal string.
     */
    private function money(int $minor, string $currency): string
    {
        return (string) Currency::fromMinorUnits($minor, $currency);
    }
}
