<?php

namespace App\Support\Payouts;

use App\ApiResponse;
use App\Contracts\PayoutProviderInterface;
use App\Enums\TransactionStatus;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Support\Market;
use App\Support\ProviderCallContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one payout through a list of providers, in order.
 *
 * It falls back to the next provider only when one definitely refused. If a
 * provider's answer is unknown, it stops: trying another provider could pay
 * the recipient twice. Used by the payout API and by cross-border transfers.
 */
class PayoutDispatcher
{
    /**
     * @param  iterable<PaymentProvider>  $providers  in the order to try them
     * @param  array{account_number: string, network?: ?string, narration?: ?string}  $recipient
     * @param  array<string, mixed>  $attributes  extra transaction fields, e.g. is_fx and fx_rate
     */
    public function send(iterable $providers, array $recipient, string $amount, string $currency, string $country, ?string $callbackUrl = null, array $attributes = []): PayoutResult
    {
        $recipient = ['network' => null, 'narration' => null, ...$recipient];
        $customer = $this->recipient($recipient['account_number'], $country);
        $lastError = null;

        foreach ($providers as $provider) {
            $driver = app($provider->class);

            if (! $driver instanceof PayoutProviderInterface) {
                $lastError = ApiResponse::error("Payout driver {$provider->class} is not available", 500);

                continue;
            }

            if ($configError = $driver->setProvider($provider)) {
                $lastError = $configError;

                continue;
            }

            $transaction = $this->newPayout($provider, $customer, $amount, $currency, $country, $callbackUrl, $attributes);
            ProviderCallContext::set($provider);

            try {
                $response = $driver->requestPayout($transaction, $recipient);
            } catch (Throwable $e) {
                report($e);

                return $this->unknown($transaction);
            } finally {
                ProviderCallContext::clear();
            }

            $code = $response->getStatusCode();
            $responseData = is_array($response->getData(true)) ? $response->getData(true) : null;

            if ($code < 300) {
                return new PayoutResult(PayoutResult::ACCEPTED, $transaction->refresh(), $response);
            }

            if ($code >= 500) {
                $transaction->update(['provider_response' => $responseData ?? $transaction->provider_response]);

                return $this->unknown($transaction);
            }

            // A definite refusal: safe to try the next provider.
            $transaction->update([
                'status' => TransactionStatus::FAILED,
                'provider_response' => $responseData ?? $transaction->provider_response,
            ]);

            $lastError = new PayoutResult(PayoutResult::REFUSED, $transaction, $response);
        }

        return $lastError instanceof PayoutResult
            ? $lastError
            : new PayoutResult(PayoutResult::REFUSED, null, $lastError ?? ApiResponse::error('No payout provider could process the request', 500));
    }

    /**
     * Find or create the recipient's customer record idempotently.
     */
    public function recipient(string $accountNumber, string $country): Customer
    {
        $msisdn = Market::msisdn($accountNumber, $country);

        return DB::transaction(function () use ($msisdn, $country): Customer {
            $customer = Customer::firstOrCreate(
                ['email' => $msisdn.'@moneyunify.local'],
                ['name' => 'Customer '.$msisdn],
            );

            CustomerAccount::updateOrCreate(
                ['number' => $msisdn, 'country' => $country],
                ['customer_id' => $customer->id, 'name' => $customer->name],
            );

            return $customer;
        });
    }

    /**
     * The payout record, created before the provider is called.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function newPayout(PaymentProvider $provider, Customer $customer, string $amount, string $currency, string $country, ?string $callbackUrl, array $attributes): Transaction
    {
        $transactionId = (string) Str::uuid();

        $transaction = new Transaction([
            ...$attributes,
            'transaction_id' => $transactionId,
            'provider_transaction_id' => $transactionId,
            'customer_id' => $customer->id,
            'amount' => $amount,
            'currency' => $currency,
            'country' => $country,
            'status' => TransactionStatus::PENDING,
            'direction' => 'debit',
            'callback_url' => $callbackUrl,
        ]);
        $transaction->paymentProvider()->associate($provider);
        $transaction->save();

        return $transaction;
    }

    /**
     * The provider gave no definite answer: keep the payout pending and stop.
     */
    private function unknown(Transaction $transaction): PayoutResult
    {
        $transaction->update(['status' => TransactionStatus::PENDING]);

        return new PayoutResult(PayoutResult::UNKNOWN, $transaction, ApiResponse::status('pending', 'The payout outcome is unknown. Verify it before retrying; no other provider was tried.', [
            'transaction_id' => $transaction->transaction_id,
            'reference' => $transaction->provider_transaction_id,
            'status' => TransactionStatus::PENDING->value,
        ], 202));
    }
}
