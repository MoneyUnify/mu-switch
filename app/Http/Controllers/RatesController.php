<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Support\Fx\Exceptions\UnsupportedCurrencyPairException;
use App\Support\Fx\FxQuoter;
use App\Support\Paypal\PaypalFxRates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RatesController extends Controller
{
    /**
     * Display the Exchange Rates & FX Quoter dashboard page.
     */
    public function index(Request $request, FxQuoter $quoter): Response
    {
        $baseCurrency = strtoupper((string) $request->input('base', 'USD'));
        $matrix = $quoter->matrix($baseCurrency, $request->user()->id);

        $activeQuote = null;
        if ($quoteId = $request->session()->get('active_fx_quote_id')) {
            $quote = $quoter->findQuote($quoteId, $request->user()->id);
            if ($quote && ! $quote->isExpired()) {
                $activeQuote = $quote->toArray();
            } else {
                $request->session()->forget('active_fx_quote_id');
            }
        }

        return Inertia::render('rates/index', [
            'baseCurrency' => $baseCurrency,
            'rates' => $matrix,
            'marginPercent' => (float) config('moneyunify_paypal.fx.margin_percent', 1.5),
            'quoteTtlMinutes' => (int) config('moneyunify_paypal.fx.quote_ttl_minutes', 10),
            'supportedCurrencies' => $quoter->getRateProvider()->supportedCurrencies(),
            'activeQuote' => $activeQuote,
            'rateSource' => $quoter->rateSourceFor($request->user()->id),
        ]);
    }

    /**
     * Ask PayPal again whether FX is enabled on the account's PayPal business
     * account, e.g. right after PayPal turned it on.
     */
    public function checkPaypalFx(Request $request, PaypalFxRates $paypalFx): RedirectResponse
    {
        if ($provider = $paypalFx->providerFor($request->user()->id)) {
            $paypalFx->forget($provider);
        }

        return back();
    }

    /**
     * Live quote calculation endpoint for the interactive UI calculator.
     */
    public function quote(Request $request, FxQuoter $quoter): JsonResponse
    {
        $validated = $request->validate([
            'from_currency' => 'required|string|size:3|alpha',
            'to_currency' => 'required|string|size:3|alpha',
            'amount' => 'required|numeric|gt:0',
            'mode' => 'nullable|string|in:source,target',
        ]);

        $mode = $validated['mode'] ?? 'source';
        $from = strtoupper($validated['from_currency']);
        $to = strtoupper($validated['to_currency']);

        try {
            $quote = $mode === 'target'
                ? $quoter->quoteTarget($from, $to, (string) $validated['amount'], ownerId: $request->user()->id)
                : $quoter->quote($from, $to, (string) $validated['amount'], ownerId: $request->user()->id);
        } catch (UnsupportedCurrencyPairException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $request->session()->put('active_fx_quote_id', $quote->id);

        return ApiResponse::success('Quote calculated', $quote->toArray());
    }
}
