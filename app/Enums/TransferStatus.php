<?php

namespace App\Enums;

enum TransferStatus: string
{
    case REQUESTED = 'requested';
    case APPROVED = 'approved';
    case IN_TRANSIT = 'in_transit';
    case SETTLED = 'settled';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    /**
     * Whether a transfer may move from this status to the next.
     */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::REQUESTED => [self::APPROVED, self::CANCELLED],
            self::APPROVED => [self::IN_TRANSIT, self::CANCELLED],
            self::IN_TRANSIT => [self::SETTLED, self::FAILED],
            self::SETTLED, self::FAILED, self::CANCELLED => [],
        }, true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::SETTLED, self::FAILED, self::CANCELLED], true);
    }
}
