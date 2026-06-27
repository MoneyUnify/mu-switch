<?php

namespace App\Contracts;

use App\Models\PaymentProvider;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

interface PaymentProviderInterface
{
    /**
     * Initiate a collection (request-to-pay) with the provider.
     */
    public function requestPayment(Request $request): JsonResponse;

    /**
     * Inject the configured provider (credentials, settings) into the driver.
     * Return a JsonResponse to short-circuit with an error, or null on success.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse;

    /**
     * Re-check a transaction against the provider, persist the latest status,
     * and return the normalised result.
     */
    public function verifyPayment(Transaction $transaction): JsonResponse;
}
