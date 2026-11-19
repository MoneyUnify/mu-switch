<?php

namespace App\Enums;

/**
 * Where a cross-border transfer is: paid in through PayPal, then paid out to
 * mobile money, or refunded to the sender if it can't be delivered.
 */
enum CrossBorderStatus: string
{
    case AWAITING_PAYMENT = 'awaiting_payment';
    case FUNDED = 'funded';
    case PAYING_OUT = 'paying_out';
    case DELIVERED = 'delivered';
    case PAYMENT_FAILED = 'payment_failed';
    case PAYOUT_FAILED = 'payout_failed';
    case REFUNDING = 'refunding';
    case REFUNDED = 'refunded';
    case REFUND_FAILED = 'refund_failed';

    /**
     * Whether a transfer may move from this status to the next.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::AWAITING_PAYMENT => [self::FUNDED, self::PAYMENT_FAILED],
            self::FUNDED => [self::PAYING_OUT, self::PAYOUT_FAILED],
            self::PAYING_OUT => [self::DELIVERED, self::PAYOUT_FAILED],
            self::PAYOUT_FAILED, self::REFUND_FAILED => [self::REFUNDING, self::REFUNDED, self::REFUND_FAILED],
            self::REFUNDING => [self::REFUNDED, self::REFUND_FAILED],
            self::DELIVERED, self::PAYMENT_FAILED, self::REFUNDED => [],
        }, true);
    }

    /**
     * Nothing more will happen to the transfer.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::DELIVERED, self::PAYMENT_FAILED, self::REFUNDED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::AWAITING_PAYMENT => 'Awaiting PayPal payment',
            self::FUNDED => 'Paid, sending to recipient',
            self::PAYING_OUT => 'Sending to recipient',
            self::DELIVERED => 'Delivered',
            self::PAYMENT_FAILED => 'PayPal payment not completed',
            self::PAYOUT_FAILED => 'Delivery failed, refunding',
            self::REFUNDING => 'Refund in progress',
            self::REFUNDED => 'Refunded to sender',
            self::REFUND_FAILED => 'Refund failed, needs attention',
        };
    }
}
