<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Contracts\PayoutProviderInterface;
use App\Contracts\RefundsPayments;
use App\Exceptions\CrossBorderException;
use App\Models\CrossBorderTransfer;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ai\AiProviderChain;
use App\Support\CrossBorder\CrossBorderTransferPresenter;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\Fx\FxQuoter;
use App\Support\Market;
use App\Support\ProviderRouter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The dashboard's cross-border screen: quote, pay with PayPal, and follow
 * each transfer from payment to delivery (or refund).
 */
class SendMoneyController extends Controller
{
    /**
     * Page sizes for the transfers list (the first is the default).
     */
    private const PER_PAGE = [10, 25, 50];

    public function __construct(private readonly CrossBorderTransferService $transfers) {}

    public function index(Request $request, FxQuoter $fx): Response
    {
        $user = $request->user();
        $search = trim($request->string('q')->toString());
        $perPage = in_array($request->integer('perPage'), self::PER_PAGE, true) ? $request->integer('perPage') : self::PER_PAGE[0];

        return Inertia::render('send/index', [
            'transfers' => CrossBorderTransfer::query()
                ->where('user_id', $user->id)
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                    ->whereLike('reference', "%{$search}%")
                    ->orWhereLike('recipient_account', "%{$search}%")
                    ->orWhereLike('recipient_name', "%{$search}%")
                    ->orWhereLike('status', '%'.str_replace(' ', '_', mb_strtolower($search)).'%')))
                ->latest('id')
                ->paginate($perPage)
                ->withQueryString()
                ->through(fn (CrossBorderTransfer $transfer): array => CrossBorderTransferPresenter::toArray($transfer)),
            'filters' => ['q' => $search ?: null],
            'perPageOptions' => self::PER_PAGE,
            'countries' => $this->payoutCountries($user),
            'sourceCurrencies' => $this->fundingCurrencies($user, $fx),
            'returned' => $this->returnedTransfer($request, $user),
            'aiReady' => AiProviderChain::for($user)->canRun(),
            'conversations' => SendMoneyAssistantController::recentConversations($user),
        ]);
    }

    /**
     * A live quote for the form (nothing is created).
     */
    public function quote(Request $request): JsonResponse
    {
        $input = $request->validate($this->quoteRules());

        try {
            return ApiResponse::success('Transfer quoted', $this->transfers->quote($request->user(), $input)->toArray());
        } catch (CrossBorderException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Start the transfer and send the browser to PayPal to pay.
     */
    public function store(Request $request): HttpResponse
    {
        $input = $request->validate([
            ...$this->quoteRules(),
            'account_number' => 'required|string|max:50',
            'recipient_name' => 'nullable|string|max:255',
            'recipient_email' => 'nullable|email',
            'network' => 'nullable|string|max:50',
            'narration' => 'nullable|string|max:100',
            'payer_email' => 'nullable|email',
            'pay_with' => 'nullable|in:paypal,card',
        ]);

        try {
            [, $checkoutUrl] = $this->transfers->request($request->user(), [...$input, 'return_url' => route('send.index')], origin: 'dashboard');
        } catch (CrossBorderException $e) {
            return $this->done($e->getMessage(), 'error');
        }

        if (! $checkoutUrl) {
            return $this->done(__('PayPal did not return a checkout link. Try again.'), 'error');
        }

        return Inertia::location($checkoutUrl);
    }

    /**
     * Re-check a transfer's legs now.
     */
    public function verify(Request $request, CrossBorderTransfer $transfer): RedirectResponse
    {
        abort_unless($transfer->user_id === $request->user()->id, 403);

        $transfer = $this->transfers->refresh($transfer);

        return $this->done($transfer->status->label().'.', $transfer->status->value === 'refund_failed' ? 'error' : 'success');
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteRules(): array
    {
        return [
            'amount' => 'required|numeric|gt:0',
            'mode' => 'nullable|string|in:source,target',
            'source_currency' => 'nullable|string|size:3|alpha',
            'country' => ['required', 'string', 'size:2', Rule::in(Market::codes())],
            'currency' => 'nullable|string|size:3|alpha',
        ];
    }

    /**
     * Countries an active payout provider can deliver to.
     *
     * @return list<array{code: string, name: string, currency: ?string}>
     */
    private function payoutCountries(User $user): array
    {
        return $this->activeProviders($user, PayoutProviderInterface::class)
            ->flatMap(fn (PaymentProvider $provider): array => (array) ($provider->config['supported_countries'] ?? (defined($provider->class.'::SUPPORTED_COUNTRIES') ? $provider->class::SUPPORTED_COUNTRIES : [])))
            ->map(fn ($code): string => strtoupper((string) $code))
            ->filter(fn (string $code): bool => Market::isKnown($code))
            ->unique()
            ->map(fn (string $code): array => ['code' => $code, 'name' => Market::name($code), 'currency' => Market::currency($code)])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Currencies the sender can pay in: what an active funding account
     * collects and FX can convert.
     *
     * @return list<string>
     */
    private function fundingCurrencies(User $user, FxQuoter $fx): array
    {
        $convertible = $fx->getRateProvider()->supportedCurrencies();

        return $this->activeProviders($user, RefundsPayments::class)
            ->flatMap(fn (PaymentProvider $provider): array => defined($provider->class.'::SUPPORTED_CURRENCIES') ? $provider->class::SUPPORTED_CURRENCIES : [])
            ->intersect($convertible)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, PaymentProvider>
     */
    private function activeProviders(User $user, string $capability)
    {
        return $user->paymentProviders()->where('is_active', true)->get()
            ->filter(fn (PaymentProvider $provider): bool => class_exists($provider->class) && ProviderRouter::supports($provider, $capability));
    }

    /**
     * The transfer the sender just came back from PayPal for, if any.
     */
    private function returnedTransfer(Request $request, User $user): ?string
    {
        $transactionId = $request->query('transaction_id');

        if (! is_string($transactionId) || $transactionId === '') {
            return null;
        }

        $funding = Transaction::query()->where('transaction_id', $transactionId)->value('id');

        return $funding ? CrossBorderTransfer::query()->where('user_id', $user->id)->where('funding_transaction_id', $funding)->value('reference') : null;
    }

    private function done(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back(fallback: route('send.index'));
    }
}
