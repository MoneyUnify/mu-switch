<?php

namespace App\Support\Treasury;

use App\Enums\TransferStatus;
use App\Exceptions\TransferException;
use App\Models\PaymentProvider;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Support\Currency;
use App\Support\Fx\FxQuoter;
use App\Support\Ledger\LedgerBalance;
use App\Support\Ledger\LedgerRecorder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves money between an account's provider accounts, one approved step at a
 * time: requested → approved → in transit → settled (or failed / cancelled).
 * Every step is recorded, and the ledger follows the transfer's state.
 */
class TransferService
{
    public function __construct(private readonly LedgerRecorder $ledger) {}

    /**
     * Ask to move money; nothing moves until it's approved and sent. The
     * treasury agent's suggestions arrive here too, with `origin` "agent".
     *
     * @throws TransferException
     */
    public function request(
        User $by,
        PaymentProvider $from,
        PaymentProvider $to,
        string $currency,
        BigDecimal $amount,
        BigDecimal $fee,
        ?string $note = null,
        string $origin = 'operator',
        ?string $reasoning = null,
        ?string $toCurrency = null,
        ?BigDecimal $targetAmount = null,
        ?string $fxRate = null,
        ?string $fxQuoteId = null,
    ): TreasuryTransfer {
        $currency = strtoupper($currency);
        $toCurrency = $toCurrency ? strtoupper($toCurrency) : null;

        if ($from->is($to)) {
            throw new TransferException('Choose two different provider accounts.');
        }

        if (! $amount->isPositive() || $fee->isNegative()) {
            throw new TransferException('The amount must be positive and the fee zero or more.');
        }

        $targetAmountMinor = null;
        if ($toCurrency && $toCurrency !== $currency) {
            if ($targetAmount === null) {
                try {
                    $quoter = app(FxQuoter::class);
                    $quote = $fxQuoteId ? $quoter->getValidQuote($fxQuoteId, $by->id) : $quoter->quote($currency, $toCurrency, $amount, ownerId: $by->id);
                    $targetAmount = $quote->targetAmount;
                    $fxRate = (string) $quote->effectiveRate;
                    $fxQuoteId = $quote->id;
                } catch (\Throwable $e) {
                    throw new TransferException('FX quote failed: '.$e->getMessage());
                }
            }

            $targetAmountMinor = Currency::toMinorUnits($targetAmount, $toCurrency);
        }

        return DB::transaction(function () use (
            $by, $from, $to, $currency, $toCurrency, $amount, $fee,
            $targetAmountMinor, $fxRate, $fxQuoteId, $note, $origin, $reasoning
        ): TreasuryTransfer {
            $transfer = TreasuryTransfer::create([
                'reference' => (string) Str::uuid(),
                'user_id' => $by->id,
                'from_provider_id' => $from->id,
                'to_provider_id' => $to->id,
                'currency' => $currency,
                'to_currency' => $toCurrency,
                'amount_minor' => Currency::toMinorUnits($amount, $currency),
                'fee_minor' => Currency::toMinorUnits($fee, $currency),
                'target_amount_minor' => $targetAmountMinor,
                'fx_rate' => $fxRate,
                'fx_quote_id' => $fxQuoteId,
                'status' => TransferStatus::REQUESTED,
                'method' => 'manual',
                'origin' => $origin,
                'note' => $note,
                'reasoning' => $reasoning,
            ]);

            $transfer->events()->create(['user_id' => $by->id, 'from_status' => null, 'to_status' => TransferStatus::REQUESTED, 'note' => $note, 'created_at' => now()]);

            return $transfer;
        });
    }

    /**
     * Approve a requested transfer, if the source account can cover it.
     *
     * @throws TransferException
     */
    public function approve(TreasuryTransfer $transfer, User $by): TreasuryTransfer
    {
        $this->ensureCovered($transfer);

        return $this->transition($transfer, TransferStatus::APPROVED, $by, null, ['approved_at' => now()]);
    }

    /**
     * Mark an approved transfer as sent: the money leaves the source account.
     *
     * @throws TransferException
     */
    public function send(TreasuryTransfer $transfer, User $by, ?string $externalReference = null): TreasuryTransfer
    {
        $this->ensureCovered($transfer);

        return $this->transition($transfer, TransferStatus::IN_TRANSIT, $by, null, array_filter([
            'sent_at' => now(),
            'external_reference' => $externalReference,
        ]));
    }

    /**
     * Confirm the money arrived. Less than was sent is reconciled as a fee.
     *
     * @throws TransferException
     */
    public function settle(TreasuryTransfer $transfer, User $by, ?BigDecimal $received = null, ?string $externalReference = null): TreasuryTransfer
    {
        $targetCurrency = $transfer->destinationCurrency();
        $expectedMinor = $transfer->target_amount_minor ?? $transfer->amount_minor;
        $receivedMinor = $received === null ? $expectedMinor : Currency::toMinorUnits($received, $targetCurrency);

        if ($receivedMinor <= 0 || $receivedMinor > $expectedMinor) {
            throw new TransferException('The amount received must be more than zero and no more than the amount sent.');
        }

        $shortfall = $expectedMinor - $receivedMinor;
        $note = $shortfall > 0
            ? 'Received '.Currency::fromMinorUnits($receivedMinor, $targetCurrency).' of '.Currency::fromMinorUnits($expectedMinor, $targetCurrency).' '.$targetCurrency
            : null;

        return $this->transition($transfer, TransferStatus::SETTLED, $by, $note, array_filter([
            'settled_at' => now(),
            'received_minor' => $receivedMinor,
            'external_reference' => $externalReference,
        ], fn ($value) => $value !== null));
    }

    /**
     * Record that a sent transfer never arrived: its effect on the ledger is reversed.
     *
     * @throws TransferException
     */
    public function fail(TreasuryTransfer $transfer, User $by, string $reason): TreasuryTransfer
    {
        return $this->transition($transfer, TransferStatus::FAILED, $by, $reason, ['failure_reason' => $reason]);
    }

    /**
     * Withdraw a transfer that hasn't been sent.
     *
     * @throws TransferException
     */
    public function cancel(TreasuryTransfer $transfer, User $by, ?string $reason = null): TreasuryTransfer
    {
        return $this->transition($transfer, TransferStatus::CANCELLED, $by, $reason);
    }

    /**
     * The source account must hold the amount plus the fee.
     *
     * @throws TransferException
     */
    private function ensureCovered(TreasuryTransfer $transfer): void
    {
        $balance = Currency::toMinorUnits(LedgerBalance::of($transfer->fromProvider, $transfer->currency), $transfer->currency);
        $needed = $transfer->amount_minor + $transfer->fee_minor;

        if ($balance < $needed) {
            throw new TransferException('Insufficient balance: '.$transfer->fromProvider->name.' holds '
                .Currency::fromMinorUnits($balance, $transfer->currency).' '.$transfer->currency
                .', and this transfer needs '.Currency::fromMinorUnits($needed, $transfer->currency).'.');
        }
    }

    /**
     * Move to the next status if allowed, record who did it, and update the ledger.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws TransferException
     */
    private function transition(TreasuryTransfer $transfer, TransferStatus $next, User $by, ?string $note, array $attributes = []): TreasuryTransfer
    {
        DB::transaction(function () use ($transfer, $next, $by, $note, $attributes): void {
            $current = TreasuryTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            $from = $current->status;

            if (! $from->canTransitionTo($next)) {
                throw new TransferException('A '.str_replace('_', ' ', $from->value).' transfer can\'t become '.str_replace('_', ' ', $next->value).'.');
            }

            $current->update([...$attributes, 'status' => $next]);
            $current->events()->create([
                'user_id' => $by->id,
                'from_status' => $from,
                'to_status' => $next,
                'note' => $note,
                'created_at' => now(),
            ]);
        });

        $transfer->refresh();
        $this->ledger->syncTransfer($transfer);

        return $transfer;
    }
}
