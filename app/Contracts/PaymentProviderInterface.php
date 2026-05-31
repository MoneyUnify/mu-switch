<?php

namespace App\Contracts;
use App\Models\PaymentProvider;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
interface   PaymentProviderInterface
{   
    public function requestPayment(Request $request):  JsonResponse;

    public function setProvider(PaymentProvider $provider): ?JsonResponse;
    // public function getTransactionStatus(): Transaction;
}
