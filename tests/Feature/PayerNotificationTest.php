<?php

use App\Enums\CrossBorderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Providers\SandboxPayoutController;
use App\Models\CrossBorderTransfer;
use App\Models\Merchant;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\MerchantPaid;
use App\Notifications\PaymentRefunded;
use App\Notifications\RecipientPaid;
use App\Notifications\TransferDelivered;
use App\Support\CrossBorder\CrossBorderTransferService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

beforeEach(function () {
    Sleep::fake();
    Cache::flush();
    Notification::fake();

    $this->user = User::factory()->create();
    $this->paypal = paypalProvider($this->user);
    PaymentProvider::create([
        'user_id' => $this->user->id,
        'name' => 'Simulated Payouts',
        'class' => SandboxPayoutController::class,
        'config' => ['supported_countries' => ['ZM']],
        'is_active' => true,
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response(['access_token' => 'A21-paypal-token', 'expires_in' => 32400]),
        '*/v2/checkout/orders/ORDER-1/capture' => Http::response(paypalOrder('ORDER-1', 'COMPLETED', 'COMPLETED'), 201),
        '*/v2/checkout/orders/ORDER-1' => Http::response(paypalOrder('ORDER-1', 'APPROVED')),
        '*/v2/checkout/orders' => Http::response(paypalOrder(), 201),
        '*/v2/payments/captures/CAPTURE-1/refund' => Http::response(['id' => 'REFUND-1', 'status' => 'COMPLETED'], 201),
    ]);
});

/**
 * Start a transfer, then let PayPal capture it and the payout run its course.
 *
 * @param  array<string, mixed>  $input
 */
function deliverTransfer(User $user, array $input = []): CrossBorderTransfer
{
    $service = app(CrossBorderTransferService::class);
    [$transfer] = $service->request($user, [
        'amount' => '2500',
        'mode' => 'target',
        'country' => 'ZM',
        'account_number' => '0977123456',
        'recipient_name' => 'Mum',
        'payer_email' => 'chipo@example.com',
        ...$input,
    ]);

    $service->refresh($transfer); // captured, paying out
    $service->refresh($transfer->refresh()); // delivered, or refunded if refused

    return $transfer->refresh();
}

function sentTo(string $email): Closure
{
    return fn ($notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === $email;
}

test('the payer is emailed once when their transfer is delivered', function () {
    $transfer = deliverTransfer($this->user);

    expect($transfer->status)->toBe(CrossBorderStatus::DELIVERED);
    Notification::assertSentOnDemandTimes(TransferDelivered::class, 1);
    Notification::assertSentOnDemand(TransferDelivered::class, sentTo('chipo@example.com'));
    Notification::assertSentOnDemandTimes(PaymentRefunded::class, 0);

    // Checking the transfer again doesn't email them again.
    app(CrossBorderTransferService::class)->refresh($transfer);
    Notification::assertSentOnDemandTimes(TransferDelivered::class, 1);
});

test('the delivery email says what arrived, what was paid and links the paypal receipt', function () {
    $transfer = deliverTransfer($this->user);

    $mail = (new TransferDelivered($transfer))->toMail(new AnonymousNotifiable);
    $text = implode("\n", $mail->introLines);

    expect($mail->subject)->toBe('Your money was delivered')
        ->and($text)->toContain('Mum received ZMW 2,500.00 on mobile money in Zambia.')
        ->and($text)->toContain('You paid USD 92.30 through PayPal.')
        ->and($text)->toContain('1 USD = 27.0875 ZMW')
        ->and($text)->toContain("Reference: {$transfer->reference}")
        ->and($mail->actionUrl)->toContain('/receipts/paypal/'.$transfer->fundingTransaction->transaction_id)
        ->and($mail->actionUrl)->toContain('signature=');
});

test('payers who gave no email are not emailed', function () {
    deliverTransfer($this->user, ['payer_email' => null]);

    Notification::assertNothingSent();
});

test('a transfer that cannot be delivered emails the payer that they were refunded', function () {
    // Simulated Payouts refuses numbers ending in 0000.
    $transfer = deliverTransfer($this->user, ['account_number' => '0977120000']);

    expect($transfer->status)->toBe(CrossBorderStatus::REFUNDED);
    Notification::assertSentOnDemandTimes(PaymentRefunded::class, 1);
    Notification::assertSentOnDemand(PaymentRefunded::class, sentTo('chipo@example.com'));
    Notification::assertSentOnDemandTimes(TransferDelivered::class, 0);

    $mail = (new PaymentRefunded($transfer->refundTransaction, $transfer))->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toBe('Your payment was refunded')
        ->and(implode("\n", $mail->introLines))->toContain("We couldn't complete your payment to Mum, so we refunded USD 92.30 to you through PayPal.");
});

test('a merchant customer is told the merchant was paid', function () {
    $merchant = Merchant::factory()->for($this->user)->create(['name' => 'Kitwe Crafts', 'wallet_number' => '260977123456']);
    $service = app(CrossBorderTransferService::class);

    [$transfer] = $service->collectForMerchant($merchant, ['amount' => '2500', 'payer_email' => 'buyer@example.com']);
    $service->refresh($transfer);
    $service->refresh($transfer->refresh());

    Notification::assertSentOnDemand(TransferDelivered::class, function (TransferDelivered $notification, array $channels, AnonymousNotifiable $notifiable) use ($transfer): bool {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === 'buyer@example.com'
            && $mail->subject === 'Your payment to Kitwe Crafts is complete'
            && $mail->actionUrl === route('pay.receipt', $transfer->reference);
    });
});

test('the recipient is emailed once when the sender gave their email', function () {
    $transfer = deliverTransfer($this->user, ['recipient_email' => 'mum@example.com', 'narration' => 'School fees']);

    Notification::assertSentOnDemandTimes(RecipientPaid::class, 1);
    Notification::assertSentOnDemand(RecipientPaid::class, sentTo('mum@example.com'));
    Notification::assertSentOnDemand(TransferDelivered::class, sentTo('chipo@example.com'));

    app(CrossBorderTransferService::class)->refresh($transfer);
    Notification::assertSentOnDemandTimes(RecipientPaid::class, 1);

    $mail = (new RecipientPaid($transfer))->toMail(new AnonymousNotifiable);
    $text = implode("\n", $mail->introLines);

    expect($mail->subject)->toBe("You've received ZMW 2,500.00")
        ->and($mail->greeting)->toBe('Hi Mum,')
        ->and($text)->toContain('ZMW 2,500.00 has been paid to your mobile money wallet •••3456 in Zambia.')
        ->and($text)->toContain('It was sent by chipo@example.com through PayPal.')
        ->and($text)->toContain('Note from the sender: School fees')
        ->and($text)->toContain("Reference: {$transfer->reference}");
});

test('the recipient is not emailed when the transfer is refunded', function () {
    deliverTransfer($this->user, ['account_number' => '0977120000', 'recipient_email' => 'mum@example.com']);

    Notification::assertSentOnDemandTimes(RecipientPaid::class, 0);
});

test('the merchant is emailed once when a customer payment reaches their wallet', function () {
    $merchant = Merchant::factory()->for($this->user)->create(['name' => 'Kitwe Crafts', 'email' => 'owner@kitwecrafts.example', 'wallet_number' => '260977123456']);
    $service = app(CrossBorderTransferService::class);

    [$transfer] = $service->collectForMerchant($merchant, ['amount' => '2500', 'payer_email' => 'buyer@example.com']);
    $service->refresh($transfer);
    $service->refresh($transfer->refresh());
    $service->refresh($transfer->refresh());

    Notification::assertSentOnDemandTimes(MerchantPaid::class, 1);
    Notification::assertSentOnDemandTimes(RecipientPaid::class, 0);
    Notification::assertSentOnDemand(MerchantPaid::class, function (MerchantPaid $notification, array $channels, AnonymousNotifiable $notifiable) use ($transfer): bool {
        $mail = $notification->toMail($notifiable);
        $text = implode("\n", $mail->introLines);

        return $notifiable->routes['mail'] === 'owner@kitwecrafts.example'
            && str_starts_with($mail->subject, "You've been paid ")
            && str_contains($text, 'Your customer (buyer@example.com) paid')
            && $mail->actionUrl === route('pay.receipt', $transfer->reference);
    });
});

test('a refund of an ordinary paypal payment emails the customer, once', function () {
    $payment = pendingPaypalTransaction($this->paypal, ['status' => TransactionStatus::SUCCESS]);
    $refund = Transaction::create([
        'transaction_id' => (string) Str::uuid(),
        'payment_provider_id' => $this->paypal->id,
        'provider_transaction_id' => 'REFUND-9',
        'customer_id' => $payment->customer_id,
        'amount' => 10,
        'currency' => 'USD',
        'country' => 'ZM',
        'status' => TransactionStatus::PENDING,
        'direction' => 'debit',
        'refund_of_id' => $payment->id,
    ]);

    Notification::assertNothingSent();

    $refund->update(['status' => TransactionStatus::SUCCESS]);
    $refund->update(['provider_response' => ['synced' => true]]);

    Notification::assertSentOnDemandTimes(PaymentRefunded::class, 1);
    Notification::assertSentOnDemand(PaymentRefunded::class, function (PaymentRefunded $notification, array $channels, AnonymousNotifiable $notifiable) use ($payment): bool {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === 'payer@example.com'
            && $mail->subject === "You've been refunded USD 10.00"
            && str_contains(implode("\n", $mail->introLines), 'USD 10.00 of your USD 25.00 payment was refunded to you through PayPal.')
            && str_contains((string) $mail->actionUrl, '/receipts/paypal/'.$payment->transaction_id);
    });
});

test('a refund that fails is not announced', function () {
    $payment = pendingPaypalTransaction($this->paypal, ['status' => TransactionStatus::SUCCESS]);

    Transaction::create([
        'transaction_id' => (string) Str::uuid(),
        'payment_provider_id' => $this->paypal->id,
        'provider_transaction_id' => 'REFUND-10',
        'customer_id' => $payment->customer_id,
        'amount' => 25,
        'currency' => 'USD',
        'country' => 'ZM',
        'status' => TransactionStatus::FAILED,
        'direction' => 'debit',
        'refund_of_id' => $payment->id,
    ]);

    Notification::assertNothingSent();
});
