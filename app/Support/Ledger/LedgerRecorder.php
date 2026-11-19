<?php

namespace App\Support\Ledger;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransferStatus;
use App\Models\LedgerEntry;
use App\Models\PaymentProvider;
use App\Models\Transaction;
use App\Models\TreasuryTransfer;
use App\Support\Currency;
use App\Support\Fees\FeeCalculator;
use App\Support\Treasury\FloatMonitor;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes double-entry ledger records for money events. Each event's entries
 * sum to zero across the provider, counterparty and fees accounts, so a
 * provider account's balance is always explainable.
 *
 * Entries mirror the source's current state: syncing again updates them in
 * place, and a source that no longer moves money has none.
 */
class LedgerRecorder
{
    public function __construct(private readonly FloatMonitor $floats) {}

    /**
     * Bring a transaction's entries in line with its state: settled
     * transactions get entries (updated if fees change), others get none.
     */
    public function sync(Transaction $transaction): void
    {
        $provider = $transaction->paymentProvider;

        if (! $provider) {
            return;
        }

        $planned = $transaction->status === TransactionStatus::SUCCESS ? $this->transactionEntries($transaction, $provider) : [];

        $this->write($transaction, $provider->user_id, $transaction->currency, $planned, [$provider]);
    }

    /**
     * Bring a transfer's entries in line with its state: in transit moves the
     * money out of the source; settled also moves it into the destination.
     */
    public function syncTransfer(TreasuryTransfer $transfer): void
    {
        $from = $transfer->fromProvider;
        $to = $transfer->toProvider;

        $this->write($transfer, $transfer->user_id, $transfer->currency, $this->transferEntries($transfer), [$from, $to]);
    }

    /**
     * Record money already in a provider account when the ledger starts.
     */
    public function recordOpeningBalance(PaymentProvider $provider, string $currency, BigDecimal|int|float|string $amount, ?string $note = null): void
    {
        $currency = strtoupper($currency);
        $minor = Currency::toMinorUnits($amount, $currency);

        DB::transaction(function () use ($provider, $currency, $minor, $note): void {
            foreach ([[LedgerAccount::PROVIDER, $minor], [LedgerAccount::COUNTERPARTY, -$minor]] as [$account, $amountMinor]) {
                LedgerEntry::create([
                    'user_id' => $provider->user_id,
                    'payment_provider_id' => $provider->id,
                    'account' => $account->value,
                    'type' => LedgerEntryType::OPENING_BALANCE->value,
                    'currency' => $currency,
                    'amount_minor' => $amountMinor,
                    'occurred_at' => now(),
                    'meta' => $note ? ['note' => $note] : null,
                ]);
            }
        });

        $this->floats->checkAccount($provider, $currency);
    }

    /**
     * Upsert the planned entries for a source, remove any that no longer
     * apply, then re-check the affected accounts' float rules.
     *
     * @param  list<array{0: LedgerEntryType, 1: LedgerAccount, 2: int, 3: BigDecimal, 4: bool, 5?: string}>  $planned  type, account, provider id, amount, estimated, currency
     * @param  list<?PaymentProvider>  $affected
     */
    private function write(Model $source, int $userId, string $currency, array $planned, array $affected): void
    {
        DB::transaction(function () use ($source, $userId, $currency, $planned): void {
            $kept = [];

            foreach ($planned as $item) {
                [$type, $account, $providerId, $amount, $estimated] = $item;
                $entryCurrency = $item[5] ?? $currency;

                $entry = LedgerEntry::firstOrNew([
                    'source_type' => $source->getMorphClass(),
                    'source_id' => $source->getKey(),
                    'type' => $type->value,
                    'account' => $account->value,
                ]);

                $entry->fill([
                    'user_id' => $userId,
                    'payment_provider_id' => $providerId,
                    'currency' => $entryCurrency,
                    'amount_minor' => Currency::toMinorUnits($amount, $entryCurrency),
                    'estimated' => $estimated,
                ]);
                // When the money moved, kept from the first time it was recorded.
                $entry->occurred_at ??= now();
                $entry->save();

                $kept[] = $entry->id;
            }

            LedgerEntry::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->whereNotIn('id', $kept)
                ->delete();
        });

        foreach (array_filter($affected) as $provider) {
            $this->floats->checkAccount($provider, $currency);

            if ($source instanceof TreasuryTransfer && $source->isFx()) {
                $this->floats->checkAccount($provider, $source->destinationCurrency());
            }
        }
    }

    /**
     * The balanced entries for a settled transaction.
     *
     * @return list<array{0: LedgerEntryType, 1: LedgerAccount, 2: int, 3: BigDecimal, 4: bool}>
     */
    private function transactionEntries(Transaction $transaction, PaymentProvider $provider): array
    {
        $amount = FeeCalculator::money((string) $transaction->amount);
        $fees = FeeCalculator::money((string) ($transaction->collection_fee ?? 0))
            ->plus(FeeCalculator::money((string) ($transaction->settlement_fee ?? 0)));
        $estimated = (bool) $transaction->fee_estimated;

        // A collection adds money to the provider account; a payout or a refund takes it out.
        $type = match (true) {
            $transaction->direction !== 'debit' => LedgerEntryType::COLLECTION,
            $transaction->refund_of_id !== null => LedgerEntryType::REFUND,
            default => LedgerEntryType::PAYOUT,
        };
        $into = $type === LedgerEntryType::COLLECTION ? $amount : $amount->negated();

        $entries = [
            [$type, LedgerAccount::PROVIDER, $provider->id, $into, false],
            [$type, LedgerAccount::COUNTERPARTY, $provider->id, $into->negated(), false],
        ];

        if ($fees->isPositive()) {
            $entries[] = [LedgerEntryType::FEE, LedgerAccount::PROVIDER, $provider->id, $fees->negated(), $estimated];
            $entries[] = [LedgerEntryType::FEE, LedgerAccount::FEES, $provider->id, $fees, $estimated];
        }

        return $entries;
    }

    /**
     * The balanced entries for a transfer in its current state.
     *
     * @return list<array{0: LedgerEntryType, 1: LedgerAccount, 2: int, 3: BigDecimal, 4: bool, 5?: string}>
     */
    private function transferEntries(TreasuryTransfer $transfer): array
    {
        if (! in_array($transfer->status, [TransferStatus::IN_TRANSIT, TransferStatus::SETTLED], true)) {
            return [];
        }

        $sourceCurrency = $transfer->currency;
        $targetCurrency = $transfer->destinationCurrency();
        $amount = Currency::fromMinorUnits($transfer->amount_minor, $sourceCurrency);
        $fee = Currency::fromMinorUnits($transfer->fee_minor, $sourceCurrency);

        // Sent: out of the source account and into transit (the counterparty).
        $entries = [
            [LedgerEntryType::TRANSFER_OUT, LedgerAccount::PROVIDER, $transfer->from_provider_id, $amount->negated(), false, $sourceCurrency],
            [LedgerEntryType::TRANSFER_OUT, LedgerAccount::COUNTERPARTY, $transfer->from_provider_id, $amount, false, $sourceCurrency],
        ];

        if ($fee->isPositive()) {
            $entries[] = [LedgerEntryType::FEE, LedgerAccount::PROVIDER, $transfer->from_provider_id, $fee->negated(), false, $sourceCurrency];
            $entries[] = [LedgerEntryType::FEE, LedgerAccount::FEES, $transfer->from_provider_id, $fee, false, $sourceCurrency];
        }

        if ($transfer->status !== TransferStatus::SETTLED) {
            return $entries;
        }

        // Received: out of transit and into the destination account.
        $expectedTarget = Currency::fromMinorUnits($transfer->target_amount_minor ?? $transfer->amount_minor, $targetCurrency);
        $received = Currency::fromMinorUnits($transfer->received_minor ?? ($transfer->target_amount_minor ?? $transfer->amount_minor), $targetCurrency);
        $shortfall = $expectedTarget->minus($received);

        $entries[] = [LedgerEntryType::TRANSFER_IN, LedgerAccount::PROVIDER, $transfer->to_provider_id, $received, false, $targetCurrency];
        $entries[] = [LedgerEntryType::TRANSFER_IN, LedgerAccount::COUNTERPARTY, $transfer->to_provider_id, $received->negated(), false, $targetCurrency];

        if ($shortfall->isPositive()) {
            $entries[] = [LedgerEntryType::ADJUSTMENT, LedgerAccount::COUNTERPARTY, $transfer->to_provider_id, $shortfall->negated(), false, $targetCurrency];
            $entries[] = [LedgerEntryType::ADJUSTMENT, LedgerAccount::FEES, $transfer->to_provider_id, $shortfall, false, $targetCurrency];
        }

        return $entries;
    }
}
