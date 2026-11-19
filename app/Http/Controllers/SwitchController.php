<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\Exceptions\NoEligibleProviderException;
use App\Models\Transaction;
use App\Support\Currency;
use App\Support\Fees\FeeCalculator;
use App\Support\Fees\PaymentQuoter;
use App\Support\Fees\ProviderQuote;
use App\Support\Market;
use App\Support\ProviderCallContext;
use App\Support\ProviderRouter;
use App\Support\TransactionVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SwitchController extends Controller
{
    public function requestPayment(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'account_number' => 'required|string',
            'country' => ['required', 'string', 'size:2', Rule::in(Market::codes())],
            // Optional payer email — forwarded to providers that accept one.
            'email' => 'nullable|email',
            // Optional: where we POST the final (successful/failed) result.
            'callback_url' => 'nullable|url',
            // Optional: charge in this currency instead of the country's own (e.g. USD via PayPal).
            'currency' => 'nullable|string|size:3|alpha',
            // Optional: where hosted checkouts (e.g. PayPal) send the payer back to.
            'return_url' => 'nullable|url',
            // Optional: allow routing to providers that convert currency via FX (e.g. PayPal for African currencies).
            'allow_fx' => 'nullable|boolean',
            'fx_quote_id' => 'nullable|string',
        ]);
        $country = strtoupper($request['country']);
        $currency = Currency::resolve($request->input('currency'), $country);
        $allowFx = (bool) $request->boolean('allow_fx') || $request->filled('fx_quote_id');

        try {
            $filteredProviders = ProviderRouter::route($request->user(), $country, $currency, (float) $request['amount'], allowFx: $allowFx);
        } catch (NoEligibleProviderException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        // 2. Iterate through filtered providers sequentially, falling back to the
        //    next active provider on ANY failure — a non-success response, a
        //    config error, or an exception (e.g. failed authentication or an
        //    unreachable gateway). One provider's failure must never abort the
        //    chain while another could still process the request.
        //
        //    Deliberately NO total time bound: this is a financial routing
        //    switch, and abandoning a provider that merely responded slowly (it
        //    may already have initiated the collection) to re-route to another
        //    risks billing the customer twice. We only ever move on when a
        //    provider definitively did not take the payment.
        $lastError = null;

        foreach ($filteredProviders as $provider) {
            $providerClass = $provider->class;

            if (! class_exists($providerClass)) {
                $lastError = ApiResponse::error("Payment driver {$providerClass} does not exist.", 500);

                continue;
            }

            // Attribute this provider's outgoing gateway calls (incl. auth) to it.
            ProviderCallContext::set($provider);

            try {
                $providerInstance = app($providerClass);

                if (! $providerInstance instanceof PaymentProviderInterface) {
                    $lastError = ApiResponse::error('Payment Driver must implement PaymentProviderInterface', 500);

                    continue;
                }

                $configError = $providerInstance->setProvider($provider);
                if ($configError instanceof JsonResponse) {
                    $lastError = $configError;

                    continue;
                }

                // Execute the payment on the actual instance.
                $response = $providerInstance->requestPayment($request);

                // A 2xx means the provider accepted/initiated the request — done.
                if ($response instanceof JsonResponse && $response->getStatusCode() < 300) {
                    return $response;
                }

                // Any other outcome (auth failure, decline, server error) — keep
                // it as the fallback result and try the next active provider.
                $lastError = $response instanceof JsonResponse
                    ? $response
                    : ApiResponse::error('Invalid response from provider', 500);
            } catch (\Throwable $e) {
                // A provider that throws (hard auth failure, network/connection
                // error, etc.) must not stop the switch — log it and move on.
                report($e);

                $lastError = ApiResponse::error("Provider {$provider->name} is currently unavailable", 502);
            } finally {
                ProviderCallContext::clear();
            }
        }

        return $lastError ?? ApiResponse::error('No provider could process the payment request', 500);
    }

    /**
     * Quote a payment's fees for every provider that would take it, before
     * charging anyone. Each quote shows both sides: the merchant absorbing the
     * fees, and the payer being charged extra to cover them.
     */
    public function quotePayment(Request $request, PaymentQuoter $quoter): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'country' => ['required', 'string', 'size:2', Rule::in(Market::codes())],
            'currency' => 'nullable|string|size:3|alpha',
            'allow_fx' => 'nullable|boolean',
            'fx_quote_id' => 'nullable|string',
        ]);

        $country = strtoupper($request['country']);
        $currency = Currency::resolve($request->input('currency'), $country);
        $amount = FeeCalculator::money((string) $request->input('amount'));
        $decimals = Currency::decimals($currency);
        $allowFx = (bool) $request->boolean('allow_fx') || $request->filled('fx_quote_id');

        if ($amount->strippedOfTrailingZeros()->getScale() > $decimals) {
            return ApiResponse::error("{$currency} amounts can have at most {$decimals} decimal places", 422);
        }

        try {
            $quotes = $quoter->quote($request->user(), $amount, $country, $currency, allowFx: $allowFx);
        } catch (NoEligibleProviderException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        return ApiResponse::success('Quote generated', [
            'amount' => (string) $amount->toScale($decimals),
            'currency' => $currency,
            'country' => $country,
            'quoted_at' => now()->toIso8601String(),
            'quotes' => array_map(fn (ProviderQuote $quote): array => $quote->toArray(), $quotes),
        ]);
    }

    /**
     * Verify (re-check) a transaction's status through its original provider.
     *
     * The switch looks up the transaction, asks the provider that processed it
     * for the latest status, persists it, and — once the transaction settles —
     * notifies the merchant's callback URL if one was supplied.
     */
    public function verifyPayment(Request $request, TransactionVerifier $verifier): JsonResponse
    {
        $validated = $request->validate([
            'transaction_id' => 'required|string',
        ]);

        $user = $request->user();
        $providerIds = $user ? $user->paymentProviders()->pluck('id') : collect();

        // Scope the lookup to the authenticated account's providers.
        $transaction = Transaction::query()
            ->whereIn('payment_provider_id', $providerIds)
            ->where('transaction_id', $validated['transaction_id'])
            ->with('paymentProvider')
            ->first();

        if (! $transaction) {
            return ApiResponse::error('Transaction not found', 404);
        }

        return $verifier->verify($transaction);
    }
}
