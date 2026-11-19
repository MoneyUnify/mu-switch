<?php

namespace App\Support\Notifications;

use App\Enums\TransactionStatus;
use App\Models\CrossBorderTransfer;
use App\Models\Transaction;
use App\Notifications\MerchantPaid;
use App\Notifications\PaymentRefunded;
use App\Notifications\RecipientPaid;
use App\Notifications\TransferDelivered;
use App\Support\Currency;
use Brick\Math\BigDecimal;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Emails the payer when their money arrives or comes back: a cross-border
 * transfer or merchant payment delivered end to end, or a refund that
 * completed. On delivery the other side is told too: the recipient, when
 * the sender gave their email, or the merchant. Only people with an email
 * are told, and each is told once.
 */
class PayerNotifier
{
    /** How long a sent notice is remembered, so it's never sent twice. */
    private const REMEMBER_DAYS = 90;

    public function transferDelivered(CrossBorderTransfer $transfer): void
    {
        $this->send($transfer->payer_email, "delivered:{$transfer->id}", new TransferDelivered($transfer));

        if ($transfer->merchant_id !== null) {
            $this->send($transfer->merchant?->email, "merchant-paid:{$transfer->id}", new MerchantPaid($transfer));
        } else {
            $this->send($transfer->recipient_email, "recipient-paid:{$transfer->id}", new RecipientPaid($transfer));
        }
    }

    private function send(?string $email, string $key, BaseNotification $notification): void
    {
        $email = self::email($email);

        if ($email === null || ! $this->firstTime($key)) {
            return;
        }

        Notification::route('mail', $email)->notify($notification);
    }

    /**
     * A refund just completed. A cross-border payer is emailed at the address
     * they gave for the transfer; anyone else at their customer email.
     */
    public function refundCompleted(Transaction $refund): void
    {
        if ($refund->refund_of_id === null || $refund->status !== TransactionStatus::SUCCESS) {
            return;
        }

        $transfer = CrossBorderTransfer::query()->where('funding_transaction_id', $refund->refund_of_id)->first();
        $email = $transfer
            ? self::email($transfer->payer_email)
            : self::email(Transaction::find($refund->refund_of_id)?->customer?->email);

        if ($email === null || ! $this->firstTime("refunded:{$refund->id}")) {
            return;
        }

        Notification::route('mail', $email)->notify(new PaymentRefunded($refund, $transfer));
    }

    /**
     * The receipt the payer can open: the merchant's live receipt for a
     * merchant payment, otherwise the PayPal-verified receipt.
     */
    public static function receiptUrl(CrossBorderTransfer $transfer): string
    {
        if ($transfer->merchant_id !== null) {
            return route('pay.receipt', $transfer->reference);
        }

        $payment = $transfer->fundingTransaction;

        return $payment
            ? URL::signedRoute('paypal.receipt', ['transaction' => $payment->transaction_id])
            : url('/');
    }

    /**
     * Who was paid: their name, or their number with most digits hidden.
     */
    public static function recipient(CrossBorderTransfer $transfer): string
    {
        if (filled($transfer->recipient_name)) {
            return (string) $transfer->recipient_name;
        }

        $number = (string) $transfer->recipient_account;

        return strlen($number) > 4 ? '•••'.substr($number, -4) : $number;
    }

    public static function money(BigDecimal|string $amount, string $currency): string
    {
        return $currency.' '.number_format((float) (string) $amount, Currency::decimals($currency));
    }

    private static function email(?string $email): ?string
    {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function firstTime(string $key): bool
    {
        return Cache::add("payer-notified:{$key}", true, now()->addDays(self::REMEMBER_DAYS));
    }
}
