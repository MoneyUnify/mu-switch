<?php

namespace App\Notifications;

use App\Models\CrossBorderTransfer;
use App\Models\Transaction;
use App\Support\Notifications\PayerNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Tells the payer money went back to them: a refund made from the dashboard,
 * the API or PayPal, or a transfer that couldn't be delivered.
 */
class PaymentRefunded extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Transaction $refund, public ?CrossBorderTransfer $transfer = null)
    {
        $this->afterCommit();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $payment = Transaction::find($this->refund->refund_of_id);
        $refunded = PayerNotifier::money((string) $this->refund->amount, $this->refund->currency);
        $transfer = $this->transfer?->loadMissing('merchant');

        $mail = (new MailMessage)->subject($transfer ? 'Your payment was refunded' : "You've been refunded {$refunded}");

        if ($transfer) {
            $to = $transfer->merchant ? $transfer->merchant->name : PayerNotifier::recipient($transfer);
            $mail->greeting('Refunded')
                ->line("We couldn't complete your payment to {$to}, so we refunded {$refunded} to you through PayPal.")
                ->line('Nothing was paid out, so no money is lost.');
        } else {
            $total = $payment ? PayerNotifier::money((string) $payment->amount, $payment->currency) : null;
            $mail->greeting('Refunded')
                ->line($total && $total !== $refunded
                    ? "{$refunded} of your {$total} payment was refunded to you through PayPal."
                    : "Your payment of {$refunded} was refunded to you through PayPal.");
        }

        $mail->line('It usually shows in your PayPal account within minutes. A refund to a card can take a few days to appear on your statement.')
            ->line('Reference: '.($transfer->reference ?? $payment?->transaction_id ?? $this->refund->transaction_id));

        if ($transfer) {
            $mail->action('View receipt', PayerNotifier::receiptUrl($transfer));
        } elseif ($payment) {
            $mail->action('View receipt', URL::signedRoute('paypal.receipt', ['transaction' => $payment->transaction_id]));
        }

        return $mail->salutation('Thank you for using '.config('app.name').'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['refund' => $this->refund->transaction_id, 'transfer' => $this->transfer?->reference];
    }
}
