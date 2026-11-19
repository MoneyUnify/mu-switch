<?php

namespace App\Support\Treasury;

use App\Enums\LedgerAccount;
use App\Enums\LedgerEntryType;
use App\Enums\TransferStatus;
use App\Models\FloatRule;
use App\Models\LedgerEntry;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Support\Currency;

/**
 * Every provider account's position at one moment, in exact minor units:
 * balance, float rule, open transfers and recent payout volume. It's what the
 * treasury agent reads and what its suggestions are checked against.
 */
final class TreasurySnapshot
{
    /** How far back payout volume is measured. */
    public const VOLUME_DAYS = 7;

    /**
     * @param  array<string, array{providerId: int, provider: string, currency: string, balance: int, minimum: ?int, target: ?int, inbound: int, outbound: int, payouts: int}>  $accounts  keyed by "providerId|CURRENCY"
     */
    private function __construct(public readonly array $accounts) {}

    public static function for(User $user): self
    {
        $providers = $user->paymentProviders()->get()->keyBy('id');
        $ids = $providers->modelKeys();
        $accounts = [];

        $ensure = function (int $providerId, string $currency) use (&$accounts, $providers): string {
            $key = $providerId.'|'.$currency;
            $accounts[$key] ??= [
                'providerId' => $providerId,
                'provider' => (string) $providers[$providerId]->name,
                'currency' => $currency,
                'balance' => 0,
                'minimum' => null,
                'target' => null,
                'inbound' => 0,
                'outbound' => 0,
                'payouts' => 0,
            ];

            return $key;
        };

        $since = now()->subDays(self::VOLUME_DAYS);
        $rows = LedgerEntry::query()
            ->whereIn('payment_provider_id', $ids)
            ->where('account', LedgerAccount::PROVIDER->value)
            ->selectRaw('payment_provider_id, currency, coalesce(sum(amount_minor), 0) as balance_minor')
            ->selectRaw('coalesce(sum(case when type = ? and occurred_at >= ? then -amount_minor else 0 end), 0) as payouts_minor', [LedgerEntryType::PAYOUT->value, $since])
            ->groupBy('payment_provider_id', 'currency')
            ->get();

        foreach ($rows as $row) {
            $key = $ensure((int) $row->payment_provider_id, (string) $row->currency);
            $accounts[$key]['balance'] = (int) $row->balance_minor;
            $accounts[$key]['payouts'] = (int) $row->payouts_minor;
        }

        FloatRule::query()->whereIn('payment_provider_id', $ids)->where('is_active', true)->get()
            ->each(function (FloatRule $rule) use (&$accounts, $ensure): void {
                $key = $ensure($rule->payment_provider_id, $rule->currency);
                $accounts[$key]['minimum'] = $rule->minimum_minor;
                $accounts[$key]['target'] = $rule->target_minor;
            });

        // Money already on its way in counts towards a float; money promised
        // out (but not yet sent, so still in the ledger) can't be moved twice.
        TreasuryTransfer::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [TransferStatus::REQUESTED->value, TransferStatus::APPROVED->value, TransferStatus::IN_TRANSIT->value])
            ->get()
            ->each(function (TreasuryTransfer $transfer) use (&$accounts, $ensure, $providers): void {
                if ($providers->has($transfer->to_provider_id)) {
                    $key = $ensure($transfer->to_provider_id, $transfer->currency);
                    $accounts[$key]['inbound'] += $transfer->amount_minor;
                }

                if ($transfer->status !== TransferStatus::IN_TRANSIT && $providers->has($transfer->from_provider_id)) {
                    $key = $ensure($transfer->from_provider_id, $transfer->currency);
                    $accounts[$key]['outbound'] += $transfer->amount_minor + $transfer->fee_minor;
                }
            });

        ksort($accounts);

        return new self($accounts);
    }

    /**
     * @return array{providerId: int, provider: string, currency: string, balance: int, minimum: ?int, target: ?int, inbound: int, outbound: int, payouts: int}|null
     */
    public function account(int $providerId, string $currency): ?array
    {
        return $this->accounts[$providerId.'|'.strtoupper($currency)] ?? null;
    }

    /**
     * What an account can give away while keeping its own minimum float.
     */
    public function spare(int $providerId, string $currency): int
    {
        $account = $this->account($providerId, $currency);

        return $account ? max(0, $account['balance'] - $account['outbound'] - ($account['minimum'] ?? 0)) : 0;
    }

    /**
     * What a float-ruled account still needs to reach its target (or its
     * minimum, without a target), counting money already on its way in.
     */
    public function need(int $providerId, string $currency): int
    {
        $account = $this->account($providerId, $currency);

        if (! $account || $account['minimum'] === null) {
            return 0;
        }

        return max(0, ($account['target'] ?? $account['minimum']) - $account['balance'] - $account['inbound']);
    }

    /**
     * Whether any account needs funds.
     */
    public function needsRebalancing(): bool
    {
        foreach ($this->accounts as $account) {
            if ($this->need($account['providerId'], $account['currency']) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The snapshot as exact decimal strings, for the agent to read.
     *
     * @return list<array<string, mixed>>
     */
    public function forAgent(): array
    {
        return array_values(array_map(function (array $account): array {
            $currency = $account['currency'];
            $money = fn (?int $minor): ?string => $minor === null ? null : (string) Currency::fromMinorUnits($minor, $currency);
            $dailyPayouts = intdiv($account['payouts'], self::VOLUME_DAYS);

            return [
                'provider_id' => $account['providerId'],
                'provider' => $account['provider'],
                'currency' => $currency,
                'balance' => $money($account['balance']),
                'minimum_float' => $money($account['minimum']),
                'target_float' => $money($account['target']),
                'incoming_transfers' => $money($account['inbound']),
                'committed_outgoing_transfers' => $money($account['outbound']),
                'payouts_last_'.self::VOLUME_DAYS.'_days' => $money($account['payouts']),
                'days_of_payouts_covered' => $dailyPayouts > 0 ? round($account['balance'] / $dailyPayouts, 1) : null,
                'max_can_send' => $money($this->spare($account['providerId'], $currency)),
                'max_can_receive' => $money($this->need($account['providerId'], $currency)),
            ];
        }, $this->accounts));
    }
}
