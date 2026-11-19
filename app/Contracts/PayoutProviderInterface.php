<?php

namespace App\Contracts;

use App\Models\PaymentProvider;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;

/**
 * A driver that can send money out (a payout / disbursement).
 *
 * The switch creates the payout transaction (direction `debit`) before
 * calling the driver. The response status tells the switch what may happen next:
 *
 * - 2xx: the provider accepted the payout (it may still be pending).
 * - 4xx: the provider definitely refused it, so trying another provider is safe.
 * - 5xx: the outcome is unknown. The switch stops and the payout must be
 *   verified; trying another provider could pay the recipient twice.
 *
 * A driver whose payouts need extra settings may add a static
 * `payoutReady(array $config): bool`; providers that return false are left
 * out of payout routing.
 */
interface PayoutProviderInterface
{
    /**
     * Inject the configured provider (credentials, settings) into the driver.
     * Return a JsonResponse to short-circuit with an error, or null on success.
     */
    public function setProvider(PaymentProvider $provider): ?JsonResponse;

    /**
     * Send the transaction's amount to the recipient.
     *
     * @param  array{account_number: string, network?: ?string, narration?: ?string}  $recipient
     */
    public function requestPayout(Transaction $transaction, array $recipient): JsonResponse;

    /**
     * Re-check a payout with the provider and persist its latest status.
     */
    public function verifyPayout(Transaction $transaction): JsonResponse;
}
