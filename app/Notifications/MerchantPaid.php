<?php

namespace App\Notifications;

use App\Models\CrossBorderTransfer;
use App\Support\Notifications\PayerNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a merchant a customer's PayPal payment has been settled to their
 * mobile-money wallet.
 */
class MerchantPaid extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public CrossBorderTransfer $transfer)
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
        $transfer = $this->transfer->loadMissing(['merchant', 'paymentLink']);
        $received = PayerNotifier::money($transfer->targetAmount(), $transfer->target_currency);
        $paid = PayerNotifier::money($transfer->sourceAmount(), $transfer->source_currency);

        $mail = (new MailMessage)
            ->subject("You've been paid {$received}")
            ->greeting($transfer->merchant->name)
            ->line("{$received} has been paid to your mobile money wallet.")
            ->line('Your customer'.(filled($transfer->payer_email) ? " ({$transfer->payer_email})" : '')." paid {$paid} through PayPal.");

        if ($transfer->paymentLink !== null) {
            $mail->line("Pay link: {$transfer->paymentLink->label}");
        }

        return $mail
            ->line("Reference: {$transfer->reference}")
            ->action('View receipt', PayerNotifier::receiptUrl($transfer))
            ->salutation('Paid through '.config('app.name').'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['reference' => $this->transfer->reference, 'status' => $this->transfer->status->value];
    }
}
