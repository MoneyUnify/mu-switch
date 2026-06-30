<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\Jobs\SendTransactionCallback;
use App\Models\Transaction;
use App\Support\Market;
use App\Support\ProviderCallContext;
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
        ]);
        $user = $request->user();
        $providers = $user ? $user->paymentProviders : collect();

        // Guard Clause: Check if providers exist early to avoid nesting
        if ($providers->isEmpty()) {
            return ApiResponse::error('Providers not configured yet. Please configure at least 1 (one) provider', 400);
        }

        // 1. Filter active providers that support the requested country based on their config
        $filteredProviders = $providers->filter(function ($provider) use ($request) {
            if (! $provider->is_active) {
                return false;
            }

            $config = is_string($provider->config) ? json_decode($provider->config, true) : $provider->config;

            if (isset($config['supported_countries'])) {
                $supportedCountries = is_array($config['supported_countries'])
                    ? $config['supported_countries']
                    : array_map('trim', explode(',', $config['supported_countries']));

                $supportedCountries = array_map('strtoupper', $supportedCountries);
                if (! in_array(strtoupper($request['country']), $supportedCountries)) {
                    return false;
                }
            }

            return true;
        });

        if ($filteredProviders->isEmpty()) {
            return ApiResponse::error('No active providers support the requested country', 400);
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
     * Verify (re-check) a transaction's status through its original provider.
     *
     * The switch looks up the transaction, asks the provider that processed it
     * for the latest status, persists it, and — once the transaction settles —
     * notifies the merchant's callback URL if one was supplied.
     */
    public function verifyPayment(Request $request): JsonResponse
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

        $provider = $transaction->paymentProvider;

        if (! $provider || ! class_exists($provider->class)) {
            return ApiResponse::error('The provider for this transaction is no longer available', 422);
        }

        $providerInstance = app($provider->class);

        if (! $providerInstance instanceof PaymentProviderInterface) {
            return ApiResponse::error('Payment driver must implement PaymentProviderInterface', 500);
        }

        // Attribute the verification's outgoing gateway calls to this provider.
        ProviderCallContext::set($provider);

        try {
            if ($configError = $providerInstance->setProvider($provider)) {
                return $configError;
            }

            // The provider re-checks the transaction and persists its latest status.
            $response = $providerInstance->verifyPayment($transaction);
        } finally {
            ProviderCallContext::clear();
        }

        $this->notifyCallbackIfSettled($transaction->refresh());

        return $response;
    }

    /**
     * Dispatch a one-time callback to the merchant once a transaction has
     * settled (succeeded or failed) and a callback URL was supplied.
     */
    private function notifyCallbackIfSettled(Transaction $transaction): void
    {
        if ($transaction->isFinal() && $transaction->callback_url && ! $transaction->callback_notified_at) {
            SendTransactionCallback::dispatch($transaction);
        }
    }
}
