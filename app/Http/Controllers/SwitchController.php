<?php

namespace App\Http\Controllers;

use App\ApiResponse;
use App\Contracts\PaymentProviderInterface;
use App\Models\PaymentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;

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

        // 2. Build task closures to run concurrently (using primitive IDs to avoid PDO serialization errors)
        $tasks = [];
        $requestData = $request->all();
        $requestUri = $request->getUri();
        $requestMethod = $request->getMethod();

        foreach ($filteredProviders as $provider) {
            $providerId = $provider->id;

            $tasks[] = function () use ($providerId, $requestData, $requestUri, $requestMethod) {
                // Resolve the provider and reconstruct Request inside the parallel process
                $provider = PaymentProvider::findOrFail($providerId);
                $request = Request::create($requestUri, $requestMethod, $requestData);

                // Set user resolver for authentication context
                $user = $provider->user;
                if ($user) {
                    $request->setUserResolver(fn () => $user);
                }

                $providerClass = $provider->class;

                if (! class_exists($providerClass)) {
                    return [
                        'status' => 'error',
                        'message' => "Payment driver {$providerClass} does not exist.",
                        'code' => 500,
                    ];
                }

                $providerInstance = app($providerClass);

                if (! $providerInstance instanceof PaymentProviderInterface) {
                    return [
                        'status' => 'error',
                        'message' => 'Payment Driver must implement PaymentProviderInterface',
                        'code' => 500,
                    ];
                }

                $config = $providerInstance->setProvider($provider);
                if ($config instanceof JsonResponse) {
                    return [
                        'status' => 'error',
                        'response' => $config,
                    ];
                }

                // Execute the payment on the actual instance
                $response = $providerInstance->requestPayment($request);

                if ($response instanceof JsonResponse) {
                    if ($response->getStatusCode() === 200) {
                        return [
                            'status' => 'success',
                            'response' => $response,
                        ];
                    }

                    return [
                        'status' => 'error',
                        'response' => $response,
                    ];
                }

                return [
                    'status' => 'error',
                    'message' => 'Invalid response from provider',
                    'code' => 500,
                ];
            };
        }

        // Execute concurrent tasks (runs sequentially via sync driver in tests)
        $results = Concurrency::run($tasks);

        // 3. Process results to find the first success or return the last aggregated error
        $firstSuccess = null;
        $lastError = null;

        foreach ($results as $result) {
            if ($result['status'] === 'success') {
                $firstSuccess = $result['response'];
                break;
            } else {
                if (isset($result['response'])) {
                    $lastError = $result['response'];
                } elseif (isset($result['message'])) {
                    $lastError = ApiResponse::error($result['message'], $result['code'] ?? 500);
                }
            }
        }

        if ($firstSuccess) {
            return $firstSuccess;
        }

        return $lastError ?? ApiResponse::error('No provider could process the payment request', 500);
    }
}
