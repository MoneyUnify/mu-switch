<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Contracts\PayoutProviderInterface;
use App\Enums\CrossBorderStatus;
use App\Models\CrossBorderTransfer;
use App\Models\Merchant;
use App\Models\PaymentProvider;
use App\Models\User;
use App\Support\CrossBorder\CrossBorderTransferPresenter;
use App\Support\Currency;
use App\Support\Market;
use App\Support\Merchants\MerchantCurrencies;
use App\Support\Merchants\MerchantPresenter;
use App\Support\Merchants\MerchantRegistry;
use App\Support\ProviderRouter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An aggregator's merchants: onboard them, share their pay links, and follow
 * what was collected through PayPal and settled to their wallets.
 */
class MerchantController extends Controller
{
    public function __construct(private readonly MerchantRegistry $registry) {}

    public function index(Request $request, MerchantCurrencies $currencies): Response
    {
        $user = $request->user();
        $settleIn = [];
        $search = trim($request->string('q')->toString());

        return Inertia::render('merchants/index', [
            'merchants' => $user->merchants()
                ->withCount('paymentLinks')
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                    ->whereLike('name', "%{$search}%")
                    ->orWhereLike('wallet_number', "%{$search}%")
                    ->orWhereLike('slug', "%{$search}%")))
                ->latest('id')
                ->paginate(10)
                ->withQueryString()
                ->through(fn (Merchant $merchant): array => [
                    ...MerchantPresenter::merchant($merchant, withStats: true),
                    'settlement_currencies' => $settleIn[$merchant->country] ??= $currencies->settlementCurrencies($user, $merchant->country),
                    'links_count' => $merchant->payment_links_count,
                    'mcp' => self::mcpAccess($merchant),
                ]),
            'payCurrencies' => $currencies->payCurrencies($user),
            'filters' => ['q' => $search ?: null],
            'impact' => $this->impact($user),
            'recent' => CrossBorderTransfer::query()
                ->where('user_id', $user->id)
                ->whereNotNull('merchant_id')
                ->with('merchant:id,name')
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (CrossBorderTransfer $transfer): array => [
                    ...CrossBorderTransferPresenter::toArray($transfer),
                    'merchant' => $transfer->merchant?->name,
                    'commission' => (string) Currency::fromMinorUnits($transfer->commission_minor, $transfer->target_currency),
                ]),
            'countries' => $this->payoutCountries($user),
            'mcpEndpoint' => route('mcp.merchant'),
        ]);
    }

    /**
     * Give the merchant a new MCP key, for its own apps or AI assistant. The
     * key is returned this once; any earlier key stops working.
     */
    public function issueMcpKey(Request $request, Merchant $merchant): JsonResponse
    {
        abort_unless($merchant->user_id === $request->user()->id, 404);

        $key = $merchant->issueMcpKey();

        return ApiResponse::success(__('New MCP key for :name. Copy it now: it won\'t be shown again.', ['name' => $merchant->name]), [
            'key' => $key,
            'endpoint' => route('mcp.merchant'),
            ...self::mcpAccess($merchant),
        ]);
    }

    public function revokeMcpKey(Request $request, Merchant $merchant): JsonResponse
    {
        abort_unless($merchant->user_id === $request->user()->id, 404);

        $merchant->revokeMcpKey();

        return ApiResponse::success(__(':name\'s MCP key no longer works.', ['name' => $merchant->name]), self::mcpAccess($merchant));
    }

    /**
     * Whether the merchant has an MCP key, without the key itself.
     *
     * @return array{enabled: bool, hint: ?string, issued_at: ?string}
     */
    private static function mcpAccess(Merchant $merchant): array
    {
        return [
            'enabled' => $merchant->mcp_key_hash !== null,
            'hint' => $merchant->mcp_key_hint,
            'issued_at' => $merchant->mcp_key_issued_at?->toIso8601String(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $merchant = $this->registry->save($request->user(), $request->validate(MerchantRegistry::rules()));

        return $this->done(__(':name added. Share its pay link to start collecting.', ['name' => $merchant->name]));
    }

    public function update(Request $request, Merchant $merchant): RedirectResponse
    {
        abort_unless($merchant->user_id === $request->user()->id, 404);

        $merchant = $this->registry->save($request->user(), $request->validate(MerchantRegistry::rules(updating: true)), $merchant);

        return $this->done(__(':name updated.', ['name' => $merchant->name]));
    }

    /**
     * What the account's merchants have been paid, per currency, and what it cost them.
     *
     * @return array<string, mixed>
     */
    private function impact(User $user): array
    {
        $delivered = CrossBorderTransfer::query()
            ->where('user_id', $user->id)
            ->whereNotNull('merchant_id')
            ->where('status', CrossBorderStatus::DELIVERED->value)
            ->get(['merchant_id', 'source_currency', 'source_amount_minor', 'target_currency', 'target_amount_minor', 'commission_minor', 'fx_base_rate', 'fx_margin_minor']);

        $settled = $delivered->groupBy('target_currency')->map(fn ($rows, string $currency): array => [
            'currency' => $currency,
            'settled' => (string) Currency::fromMinorUnits((int) $rows->sum('target_amount_minor'), $currency),
            'commission' => (string) Currency::fromMinorUnits((int) $rows->sum('commission_minor'), $currency),
            'payments' => $rows->count(),
        ])->values();

        $collected = $delivered->groupBy('source_currency')->map(fn ($rows, string $currency): array => [
            'currency' => $currency,
            'collected' => (string) Currency::fromMinorUnits((int) $rows->sum('source_amount_minor'), $currency),
            'fx_margin' => (string) Currency::fromMinorUnits((int) $rows->sum('fx_margin_minor'), $currency),
        ])->values();

        // All-in cost to the merchant: how much less it received than the
        // customer's payment was worth at the mid-market rate.
        $costs = $delivered->map(function (CrossBorderTransfer $transfer): ?float {
            $worth = Currency::fromMinorUnits($transfer->source_amount_minor, $transfer->source_currency)->toFloat() * (float) $transfer->fx_base_rate;
            $received = Currency::fromMinorUnits($transfer->target_amount_minor, $transfer->target_currency)->toFloat();

            return $worth > 0 ? (1 - $received / $worth) * 100 : null;
        })->filter(fn (?float $cost): bool => $cost !== null);

        $benchmark = config('moneyunify_paypal.impact.benchmark_cost_percent');

        return [
            'merchants' => $user->merchants()->count(),
            'active_merchants' => $user->merchants()->where('is_active', true)->count(),
            'paid_merchants' => $delivered->pluck('merchant_id')->unique()->count(),
            'payments' => $delivered->count(),
            'settled' => $settled,
            'collected' => $collected,
            'average_cost_percent' => $costs->isEmpty() ? null : round($costs->avg(), 2),
            'benchmark_cost_percent' => $benchmark === null ? null : (float) $benchmark,
        ];
    }

    /**
     * Countries the account can settle merchants in.
     *
     * @return list<array{code: string, name: string, currency: ?string}>
     */
    private function payoutCountries(User $user): array
    {
        return $user->paymentProviders()->where('is_active', true)->get()
            ->filter(fn (PaymentProvider $provider): bool => class_exists($provider->class) && ProviderRouter::supports($provider, PayoutProviderInterface::class))
            ->flatMap(fn (PaymentProvider $provider): array => (array) ($provider->config['supported_countries'] ?? (defined($provider->class.'::SUPPORTED_COUNTRIES') ? $provider->class::SUPPORTED_COUNTRIES : [])))
            ->map(fn ($code): string => strtoupper((string) $code))
            ->filter(fn (string $code): bool => Market::isKnown($code))
            ->unique()
            ->map(fn (string $code): array => ['code' => $code, 'name' => Market::name($code), 'currency' => Market::currency($code)])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back(fallback: route('merchants.index'));
    }
}
