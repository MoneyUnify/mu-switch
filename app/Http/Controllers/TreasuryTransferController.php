<?php

namespace App\Http\Controllers;

use App\Exceptions\TransferException;
use App\Models\PaymentProvider;
use App\Models\TreasuryTransfer;
use App\Support\Currency;
use App\Support\Fees\FeeCalculator;
use App\Support\Treasury\TransferService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Transfers between the account's provider accounts, step by step.
 */
class TreasuryTransferController extends Controller
{
    public function __construct(private readonly TransferService $transfers) {}

    /**
     * Request a transfer. Nothing moves until it's approved and sent.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $ownProvider = Rule::exists('payment_providers', 'id')->where('user_id', $user->id);

        $validated = $request->validate([
            'from_provider_id' => ['required', 'integer', $ownProvider],
            'to_provider_id' => ['required', 'integer', 'different:from_provider_id', $ownProvider],
            'currency' => 'required|string|size:3|alpha',
            'to_currency' => 'nullable|string|size:3|alpha',
            'amount' => ['required', 'numeric', 'gt:0', $this->fitsCurrency($request)],
            'fee' => ['nullable', 'numeric', 'min:0', $this->fitsCurrency($request)],
            'note' => 'nullable|string|max:500',
            'fx_quote_id' => 'nullable|string',
        ]);

        return $this->attempt(fn () => $this->transfers->request(
            $user,
            PaymentProvider::findOrFail($validated['from_provider_id']),
            PaymentProvider::findOrFail($validated['to_provider_id']),
            $validated['currency'],
            FeeCalculator::money((string) $validated['amount']),
            FeeCalculator::money((string) ($validated['fee'] ?? 0)),
            $validated['note'] ?? null,
            origin: 'operator',
            toCurrency: $validated['to_currency'] ?? null,
            fxQuoteId: $validated['fx_quote_id'] ?? null,
        ), __('Transfer requested. Approve it to continue.'));
    }

    public function approve(Request $request, TreasuryTransfer $transfer): RedirectResponse
    {
        $this->authorizeOwner($request, $transfer);

        return $this->attempt(fn () => $this->transfers->approve($transfer, $request->user()), __('Transfer approved.'));
    }

    /**
     * Mark as sent once the money has left the source account (e.g. a bank transfer was made).
     */
    public function send(Request $request, TreasuryTransfer $transfer): RedirectResponse
    {
        $this->authorizeOwner($request, $transfer);
        $validated = $request->validate(['external_reference' => 'nullable|string|max:255']);

        return $this->attempt(fn () => $this->transfers->send($transfer, $request->user(), $validated['external_reference'] ?? null), __('Transfer marked as sent.'));
    }

    /**
     * Confirm what arrived in the destination account.
     */
    public function settle(Request $request, TreasuryTransfer $transfer): RedirectResponse
    {
        $this->authorizeOwner($request, $transfer);
        $destCurrency = $transfer->destinationCurrency();
        $validated = $request->validate([
            'received' => ['nullable', 'numeric', 'gt:0', $this->fitsCurrency($request, $destCurrency)],
            'external_reference' => 'nullable|string|max:255',
        ]);

        $received = isset($validated['received']) ? FeeCalculator::money((string) $validated['received']) : null;

        return $this->attempt(fn () => $this->transfers->settle($transfer, $request->user(), $received, $validated['external_reference'] ?? null), __('Transfer settled.'));
    }

    public function fail(Request $request, TreasuryTransfer $transfer): RedirectResponse
    {
        $this->authorizeOwner($request, $transfer);
        $validated = $request->validate(['reason' => 'required|string|max:500']);

        return $this->attempt(fn () => $this->transfers->fail($transfer, $request->user(), $validated['reason']), __('Transfer marked as failed; its ledger effect was reversed.'));
    }

    public function cancel(Request $request, TreasuryTransfer $transfer): RedirectResponse
    {
        $this->authorizeOwner($request, $transfer);

        return $this->attempt(fn () => $this->transfers->cancel($transfer, $request->user()), __('Transfer cancelled.'));
    }

    /**
     * Run a transfer action, turning a refusal into an error toast.
     */
    private function attempt(Closure $action, string $success): RedirectResponse
    {
        try {
            $action();
            Inertia::flash('toast', ['type' => 'success', 'message' => $success]);
        } catch (TransferException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back(fallback: route('treasury.index'));
    }

    private function authorizeOwner(Request $request, TreasuryTransfer $transfer): void
    {
        abort_unless($transfer->user_id === $request->user()->id, 403);
    }

    /**
     * An amount may not be finer than its currency allows.
     */
    private function fitsCurrency(Request $request, ?string $currency = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($request, $currency): void {
            $code = strtoupper((string) ($currency ?? $request->input('currency')));

            if ($code === '' || ! is_numeric($value)) {
                return;
            }

            $decimals = Currency::decimals($code);

            if (FeeCalculator::money((string) $value)->strippedOfTrailingZeros()->getScale() > $decimals) {
                $fail("{$code} amounts can have at most {$decimals} decimal places.");
            }
        };
    }
}
