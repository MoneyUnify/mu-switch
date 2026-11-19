<?php

namespace App\Support\Payouts;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;

/**
 * How a payout attempt ended.
 *
 * - accepted: a provider took the payout (it may still be processing).
 * - refused: every provider definitely refused, so nothing was sent.
 * - unknown: a provider gave no definite answer. The payout must be verified
 *   before anything else is tried, or the recipient could be paid twice.
 */
final class PayoutResult
{
    public const ACCEPTED = 'accepted';

    public const REFUSED = 'refused';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $outcome,
        public readonly ?Transaction $transaction,
        public readonly JsonResponse $response,
    ) {}

    public function accepted(): bool
    {
        return $this->outcome === self::ACCEPTED;
    }

    public function refused(): bool
    {
        return $this->outcome === self::REFUSED;
    }

    public function unknown(): bool
    {
        return $this->outcome === self::UNKNOWN;
    }
}
