<?php

namespace App\Jobs;

use App\Models\ProviderWebhookEvent;
use App\Models\Transaction;
use App\Support\CrossBorder\CrossBorderTransferService;
use App\Support\TransactionVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;

/**
 * Handles a verified PayPal webhook event after the endpoint has answered:
 * re-reads the payment's order from PayPal (capturing it if it's approved,
 * and picking up refunds), and notes a reversal on the transfer it funded.
 * If PayPal can't be reached it's retried, with growing gaps.
 */
class ProcessPaypalWebhookEvent implements ShouldQueue
{
    use Queueable;

    /** Events that mean PayPal took the money back after it was captured. */
    public const REVERSAL_EVENTS = ['PAYMENT.CAPTURE.REVERSED'];

    public int $tries = 6;

    public function __construct(public ProviderWebhookEvent $event) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 900];
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('paypal-webhook-'.$this->event->id))->releaseAfter(30)];
    }

    public function handle(TransactionVerifier $verifier, CrossBorderTransferService $transfers): void
    {
        $event = $this->event->fresh();

        if (! $event || $event->processed_at) {
            return;
        }

        $transaction = self::transactionFor($event);

        if ($transaction && $verifier->verify($transaction)->getStatusCode() >= 500) {
            throw new RuntimeException("PayPal couldn't be reached for webhook event {$event->event_id}; it will be retried.");
        }

        if ($transaction && in_array($event->event_type, self::REVERSAL_EVENTS, true)) {
            $transfers->paymentReversed($transaction->fresh(), (string) data_get($event->payload, 'resource.id'));
        }

        $event->update(['processed_at' => now()]);
    }

    /**
     * The payment an event is about: by its order (order and capture events),
     * or by its capture (refund and reversal events point "up" to the capture).
     */
    public static function transactionFor(ProviderWebhookEvent $event): ?Transaction
    {
        $payload = $event->payload ?? [];
        $payments = Transaction::query()->where('payment_provider_id', $event->payment_provider_id);

        $orderId = data_get($payload, 'resource.supplementary_data.related_ids.order_id')
            ?? (data_get($payload, 'resource_type') === 'checkout-order' ? data_get($payload, 'resource.id') : null);

        if (is_string($orderId) && $orderId !== '') {
            return $payments->where('provider_transaction_id', $orderId)->first();
        }

        $captureId = data_get($payload, 'resource_type') === 'capture'
            ? data_get($payload, 'resource.id')
            : self::captureFromLinks((array) data_get($payload, 'resource.links', []));

        if (! is_string($captureId) || $captureId === '') {
            return null;
        }

        return $payments
            ->whereNull('refund_of_id')
            ->where('provider_response->order->purchase_units[0]->payments->captures[0]->id', $captureId)
            ->first();
    }

    /**
     * The capture a refund links back to ("rel": "up" → …/captures/{id}).
     *
     * @param  array<int, mixed>  $links
     */
    private static function captureFromLinks(array $links): ?string
    {
        foreach ($links as $link) {
            if (data_get($link, 'rel') === 'up' && preg_match('#/captures/([^/?]+)#', (string) data_get($link, 'href'), $match)) {
                return $match[1];
            }
        }

        return null;
    }
}
