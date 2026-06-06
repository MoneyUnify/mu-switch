<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SwitchController extends Controller
{
    public function requestPayment(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'account_number' => 'required|string',
            'country' => 'required|string|size:2|in:ZM,MW',
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

        // 2. Iterate through filtered providers sequentially, retrying on failure
        $lastError = null;

        foreach ($filteredProviders as $provider) {
            $providerClass = $provider->class;

            if (! class_exists($providerClass)) {
                $lastError = ApiResponse::error("Payment driver {$providerClass} does not exist.", 500);

                continue;
            }

            $providerInstance = app($providerClass);

            if (! $providerInstance instanceof PaymentProviderInterface) {
                $lastError = ApiResponse::error('Payment Driver must implement PaymentProviderInterface', 500);

                continue;
            }

            $config = $providerInstance->setProvider($provider);
            if ($config instanceof JsonResponse) {
                $lastError = $config;

                continue;
            }

            // Execute the payment on the actual instance
            $response = $providerInstance->requestPayment($request);

            if ($response instanceof JsonResponse) {
                if ($response->getStatusCode() === 200) {
                    return $response;
                }

                $lastError = $response;
            } else {
                $lastError = ApiResponse::error('Invalid response from provider', 500);
            }
        }

        return $lastError ?? ApiResponse::error('No provider could process the payment request', 500);
    }
}
