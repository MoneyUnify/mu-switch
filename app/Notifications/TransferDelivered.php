<?php

namespace App\Notifications;

use App\Models\CrossBorderTransfer;
use App\Support\Market;
use App\Support\Notifications\PayerNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the payer their PayPal payment made it all the way: the recipient (or
 * merchant) was paid on mobile money.
 */
class TransferDelivered extends Notification implements ShouldQueue
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
        $transfer = $this->transfer->loadMissing('merchant');
        $received = PayerNotifier::money($transfer->targetAmount(), $transfer->target_currency);
        $paid = PayerNotifier::money($transfer->sourceAmount(), $transfer->source_currency);
        $merchant = $transfer->merchant;

        $mail = (new MailMessage)
            ->subject($merchant ? "Your payment to {$merchant->name} is complete" : 'Your money was delivered')
            ->greeting($merchant ? 'Payment complete' : 'Delivered')
            ->line($merchant
                ? "{$merchant->name} has received {$received} for your payment."
                : PayerNotifier::recipient($transfer)." received {$received} on mobile money in ".Market::name($transfer->recipient_country).'.')
            ->line("You paid {$paid} through PayPal.");

        if ($transfer->source_currency !== $transfer->target_currency) {
            $mail->line("Exchange rate: 1 {$transfer->source_currency} = ".rtrim(rtrim((string) $transfer->fx_rate, '0'), '.')." {$transfer->target_currency}, locked when you paid.");
        }

        return $mail
            ->line("Reference: {$transfer->reference}")
            ->action('View receipt', PayerNotifier::receiptUrl($transfer))
            ->salutation('Thank you for using '.config('app.name').'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['reference' => $this->transfer->reference, 'status' => $this->transfer->status->value];
    }
}
