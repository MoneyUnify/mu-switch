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
 * Tells the recipient of a cross-border transfer that money from abroad has
 * landed in their mobile-money wallet, and who sent it.
 */
class RecipientPaid extends Notification implements ShouldQueue
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
        $transfer = $this->transfer;
        $received = PayerNotifier::money($transfer->targetAmount(), $transfer->target_currency);
        $wallet = (string) $transfer->recipient_account;
        $wallet = strlen($wallet) > 4 ? '•••'.substr($wallet, -4) : $wallet;

        $mail = (new MailMessage)
            ->subject("You've received {$received}")
            ->greeting(filled($transfer->recipient_name) ? "Hi {$transfer->recipient_name}," : 'Hello,')
            ->line("{$received} has been paid to your mobile money wallet {$wallet} in ".Market::name($transfer->recipient_country).'.')
            ->line('It was sent '.(filled($transfer->payer_email) ? "by {$transfer->payer_email} " : '').'through PayPal.');

        if (filled($transfer->narration)) {
            $mail->line("Note from the sender: {$transfer->narration}");
        }

        return $mail
            ->line("Reference: {$transfer->reference}")
            ->line('Your mobile money provider will also confirm the deposit.')
            ->salutation('Sent with '.config('app.name').'.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['reference' => $this->transfer->reference, 'status' => $this->transfer->status->value];
    }
}
