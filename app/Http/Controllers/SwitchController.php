<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentProviderInterface;
use Illuminate\Http\Request;
use App\ApiResponse;
use Illuminate\Http\JsonResponse;

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

        $lastError = null;

        foreach ($providers as $provider) {
            $providerClass = $provider->class;

            if (!class_exists($providerClass)) {
                return ApiResponse::error("Payment driver {$providerClass} does not exist.", 500);
            }

            $providerInstance = app($providerClass);

            if (!$providerInstance instanceof PaymentProviderInterface) {
                return ApiResponse::error("Payment Driver must implement PaymentProviderInterface", 500);
            }
            //TODO - we can optimize by running the providers in parallel instead of sequentially to reduce latency, but that would require more complex error handling and response aggregation logic
            //TODO - we can also optimize by allowing providers to specify which countries or account types they support in their config, so we can skip calling unsupported providers altogether instead of calling them and letting them fail
            $config = $providerInstance->setProvider($provider);
            if($config instanceof JsonResponse) {
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
            }
        }
        return $lastError ?? ApiResponse::error('No provider could process the payment request', 500);
    }
}