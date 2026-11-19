<?php

namespace App\Support\Paypal;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\PaypalController;
use App\Models\CrossBorderTransfer;
use App\Models\Transaction;
use App\Support\Currency;
use App\Support\TransactionVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * A receipt for a PayPal payment, built from PayPal's own record of it: the
 * capture (PayPal's transaction id, status, time, amount, seller protection),
 * the payer as PayPal identified them, and any refunds.
 *
 * It's re-read from PayPal (at most once a minute), so it shows PayPal's
 * current record. PayPal also emails the payer its official receipt; this one
 * is PayPal-verified, not a copy of that email.
 */
final class PaypalReceipt
{
    /** How long a receipt waits before asking PayPal again. */
    public const REFRESH_SECONDS = 60;

    /**
     * The receipt, or null when the transaction isn't a captured PayPal payment.
     *
     * @return array<string, mixed>|null
     */
    public static function for(Transaction $payment, bool $refresh = true): ?array
    {
        $provider = $payment->paymentProvider;

        if ($payment->direction !== 'credit' || $provider?->class !== PaypalController::class) {
            return null;
        }

        $verifiedLive = $refresh && self::refresh($payment);
        $payment->refresh();

        $order = (array) data_get($payment->provider_response, 'order', []);
        $capture = (array) data_get($order, 'purchase_units.0.payments.captures.0', []);

        if (($capture['id'] ?? null) === null) {
            return null;
        }

        $payer = (array) data_get($order, 'payment_source.paypal', []);
        $currency = (string) data_get($capture, 'amount.currency_code', $payment->currency);
        $refunds = $payment->refunds()->get();
        $refundedMinor = $refunds->where('status', TransactionStatus::SUCCESS)
            ->sum(fn (Transaction $refund): int => Currency::toMinorUnits((string) $refund->amount, $currency));
        $paidMinor = Currency::toMinorUnits((string) data_get($capture, 'amount.value', $payment->amount), $currency);
        $sandbox = ($provider->config['environment'] ?? 'live') === 'sandbox';
        $transfer = CrossBorderTransfer::query()->where('funding_transaction_id', $payment->id)->with('merchant')->first();

        return [
            'verified' => true,
            'checked_live' => $verifiedLive,
            'checked_at' => now()->toIso8601String(),
            'sandbox' => $sandbox,
            'status' => match (true) {
                $refundedMinor >= $paidMinor && $refundedMinor > 0 => 'Refunded',
                $refundedMinor > 0 => 'Partially refunded',
                ($capture['status'] ?? null) === 'COMPLETED' => 'Completed',
                default => ucfirst(strtolower(str_replace('_', ' ', (string) ($capture['status'] ?? 'pending')))),
            },
            'paypal_transaction_id' => (string) $capture['id'],
            'paypal_order_id' => (string) ($order['id'] ?? $payment->provider_transaction_id),
            'paid_at' => $capture['create_time'] ?? null,
            'amount' => (string) Currency::fromMinorUnits($paidMinor, $currency),
            'currency' => $currency,
            'refunded' => $refundedMinor > 0 ? (string) Currency::fromMinorUnits($refundedMinor, $currency) : null,
            'payer' => [
                'name' => trim(implode(' ', array_filter([data_get($payer, 'name.given_name'), data_get($payer, 'name.surname')]))) ?: null,
                'email' => self::mask(is_string($payer['email_address'] ?? null) ? $payer['email_address'] : null),
                'verified' => ($payer['account_status'] ?? null) === 'VERIFIED',
                'country' => data_get($payer, 'address.country_code'),
            ],
            'paid_to' => $transfer?->merchant?->name ?? (string) config('app.name'),
            'seller_protection' => match (data_get($capture, 'seller_protection.status')) {
                'ELIGIBLE' => 'Eligible',
                'PARTIALLY_ELIGIBLE' => 'Partially eligible',
                'NOT_ELIGIBLE' => 'Not eligible',
                default => null,
            },
            'reference' => $payment->transaction_id,
            // PayPal's own conversion into the account's currency, when it converted.
            'conversion' => self::conversion($capture, data_get($payment->provider_response, 'paypal_fx.fx_id') !== null),
            'delivery' => $transfer ? [
                'recipient_gets' => (string) $transfer->targetAmount().' '.$transfer->target_currency,
                'status' => $transfer->status->label(),
                'is_final' => $transfer->status->isFinal(),
            ] : null,
            'activity_url' => $sandbox ? 'https://www.sandbox.paypal.com/myaccount/activity' : 'https://www.paypal.com/myaccount/activity',
            'receipt_url' => URL::signedRoute('paypal.receipt', ['transaction' => $payment->transaction_id]),
        ];
    }

    /**
     * Ask PayPal for the order again (it also syncs refunds made in PayPal),
     * at most once a minute per payment.
     */
    private static function refresh(Transaction $payment): bool
    {
        if (! Cache::add("paypal-receipt:{$payment->id}", true, self::REFRESH_SECONDS)) {
            return false;
        }

        try {
            return app(TransactionVerifier::class)->verify($payment)->getStatusCode() < 300;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * The rate PayPal converted the payment at, from the capture. What the
     * account received after PayPal's fee is the merchant's business, so
     * it isn't shown.
     *
     * @param  array<string, mixed>  $capture
     * @return array{rate: string, locked: bool}|null
     */
    private static function conversion(array $capture, bool $locked): ?array
    {
        $rate = data_get($capture, 'seller_receivable_breakdown.exchange_rate');

        if (! is_array($rate) || ! isset($rate['value'], $rate['source_currency'], $rate['target_currency'])) {
            return null;
        }

        return [
            'rate' => "1 {$rate['source_currency']} = {$rate['value']} {$rate['target_currency']}",
            'locked' => $locked,
        ];
    }

    /**
     * An email with most of the name hidden, e.g. "j•••@example.com".
     */
    private static function mask(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$name, $domain] = explode('@', $email, 2);

        return mb_substr($name, 0, 1).'•••@'.$domain;
    }
}
