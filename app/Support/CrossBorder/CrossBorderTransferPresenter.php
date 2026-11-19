<?php

namespace App\Support\CrossBorder;

use App\Enums\CrossBorderStatus;
use App\Models\CrossBorderTransfer;
use App\Models\CrossBorderTransferEvent;
use App\Support\Currency;

/**
 * One shape for a cross-border transfer, shared by the API and the dashboard.
 */
final class CrossBorderTransferPresenter
{
    /**
     * Where the sender pays, while the transfer is still waiting for payment.
     */
    public static function checkoutUrl(CrossBorderTransfer $transfer): ?string
    {
        if ($transfer->status !== CrossBorderStatus::AWAITING_PAYMENT) {
            return null;
        }

        $links = (array) data_get($transfer->fundingTransaction?->provider_response, 'order.links', []);
        $payerAction = collect($links)->first(fn ($link): bool => is_array($link) && in_array($link['rel'] ?? null, ['payer-action', 'approve'], true));

        return is_string($payerAction['href'] ?? null) ? $payerAction['href'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(CrossBorderTransfer $transfer): array
    {
        $transfer->loadMissing(['fundingTransaction', 'payoutTransaction', 'refundTransaction', 'fundingProvider', 'payoutProvider', 'events']);
        $money = fn (int $minor, string $currency): string => (string) Currency::fromMinorUnits($minor, $currency);

        return [
            'reference' => $transfer->reference,
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
            'is_final' => $transfer->status->isFinal(),
            'origin' => $transfer->origin,
            'sender_pays' => ['amount' => $money($transfer->source_amount_minor, $transfer->source_currency), 'currency' => $transfer->source_currency],
            'recipient_gets' => ['amount' => $money($transfer->target_amount_minor, $transfer->target_currency), 'currency' => $transfer->target_currency],
            'recipient' => [
                'account_number' => $transfer->recipient_account,
                'country' => $transfer->recipient_country,
                'name' => $transfer->recipient_name,
            ],
            'fx' => [
                'quote_id' => $transfer->fx_quote_id,
                'base_rate' => (string) $transfer->fx_base_rate,
                'effective_rate' => (string) $transfer->fx_rate,
                'margin_percent' => (string) $transfer->fx_margin_percent,
                'margin_amount' => $money($transfer->fx_margin_minor, $transfer->source_currency),
                'rate_source' => $transfer->fx_rate_source,
            ],
            'funding' => [
                'provider' => $transfer->fundingProvider?->name,
                'transaction_id' => $transfer->fundingTransaction?->transaction_id,
                'status' => $transfer->fundingTransaction?->status->value,
            ],
            'payout' => $transfer->payoutTransaction ? [
                'provider' => $transfer->payoutProvider?->name,
                'transaction_id' => $transfer->payoutTransaction->transaction_id,
                'status' => $transfer->payoutTransaction->status->value,
            ] : null,
            'refund' => $transfer->refundTransaction ? [
                'transaction_id' => $transfer->refundTransaction->transaction_id,
                'status' => $transfer->refundTransaction->status->value,
            ] : null,
            'failure_reason' => $transfer->failure_reason,
            'created_at' => $transfer->created_at?->toIso8601String(),
            'timeline' => $transfer->events->map(fn (CrossBorderTransferEvent $event): array => [
                'status' => $event->to_status->value,
                'note' => $event->note,
                'at' => $event->created_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
