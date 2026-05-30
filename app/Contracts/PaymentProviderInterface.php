<?php

namespace App\Contracts;
use App\Models\Transaction;
interface   PaymentProviderInterface
{
    
    public function requestPayment(float $amount, string $currency, array $paymentDetails): Transaction;

    public function getTransactionStatus(string $transactionId): Transaction;
}
