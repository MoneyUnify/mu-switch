<?php

namespace App\Support\Merchants;

use App\Enums\CrossBorderStatus;
use App\Models\CrossBorderTransfer;
use App\Models\Merchant;
use App\Support\CrossBorder\CrossBorderQuote;
use App\Support\Currency;
use App\Support\Market;
use App\Support\Paypal\PaypalReceipt;

/**
 * Shapes merchants, their payment quotes and their settlements for the API,
 * the dashboard and the public pay page.
 */
final class MerchantPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function merchant(Merchant $merchant, bool $withStats = false): array
    {
        $data = [
            'id' => $merchant->id,
            'name' => $merchant->name,
            'slug' => $merchant->slug,
            'email' => $merchant->email,
            'country' => $merchant->country,
            'country_name' => Market::name($merchant->country),
            'currency' => $merchant->currency,
            'wallet_number' => $merchant->wallet_number,
            'wallet_name' => $merchant->wallet_name,
            'network' => $merchant->network,
            'commission_percent' => (string) $merchant->commission_percent,
            'is_active' => $merchant->is_active,
            'pay_url' => route('pay.show', $merchant),
            'created_at' => $merchant->created_at?->toIso8601String(),
        ];

        return $withStats ? [...$data, 'stats' => self::stats($merchant)] : $data;
    }

    /**
     * Settled totals for one merchant: payments delivered to its wallet.
     *
     * `settled` and `commission` are in the merchant's own currency; payments
     * settled in other currencies are in `by_currency`.
     *
     * @return array{payments: int, settled: string, commission: string, by_currency: list<array{currency: string, settled: string, commission: string}>, in_progress: int, refunded: int}
     */
    public static function stats(Merchant $merchant): array
    {
        $settlements = $merchant->settlements()->get(['status', 'target_amount_minor', 'commission_minor', 'target_currency']);
        $delivered = $settlements->where('status', CrossBorderStatus::DELIVERED);
        $local = $delivered->where('target_currency', $merchant->currency);

        return [
            'payments' => $delivered->count(),
            'settled' => (string) Currency::fromMinorUnits((int) $local->sum('target_amount_minor'), $merchant->currency),
            'commission' => (string) Currency::fromMinorUnits((int) $local->sum('commission_minor'), $merchant->currency),
            'by_currency' => $delivered->groupBy('target_currency')->map(fn ($rows, string $currency): array => [
                'currency' => $currency,
                'settled' => (string) Currency::fromMinorUnits((int) $rows->sum('target_amount_minor'), $currency),
                'commission' => (string) Currency::fromMinorUnits((int) $rows->sum('commission_minor'), $currency),
            ])->values()->all(),
            'in_progress' => $settlements->filter(fn (CrossBorderTransfer $t): bool => ! $t->status->isFinal())->count(),
            'refunded' => $settlements->where('status', CrossBorderStatus::REFUNDED)->count(),
        ];
    }

    /**
     * A payment quote as the customer and the merchant see it.
     *
     * @return array<string, mixed>
     */
    public static function quote(Merchant $merchant, CrossBorderQuote $quote, int $commissionMinor, int $netMinor): array
    {
        $fx = $quote->fx;
        $currency = $fx->targetCurrency;

        return [
            'customer_pays' => ['amount' => (string) $fx->sourceAmount, 'currency' => $fx->sourceCurrency],
            'merchant_price' => ['amount' => (string) $fx->targetAmount, 'currency' => $currency],
            'commission' => [
                'percent' => (string) $merchant->commission_percent,
                'amount' => (string) Currency::fromMinorUnits($commissionMinor, $currency),
                'currency' => $currency,
            ],
            'merchant_receives' => ['amount' => (string) Currency::fromMinorUnits($netMinor, $currency), 'currency' => $currency],
            'fx' => [
                'effective_rate' => (string) $fx->effectiveRate,
                'margin_percent' => (string) $fx->marginPercent,
            ],
            'settles_via' => $quote->payoutRoute->pluck('name')->first(),
            'expires_at' => $fx->expiresAt->toIso8601String(),
        ];
    }

    /**
     * A settlement as the paying customer may see it: no wallet number, no
     * provider internals.
     *
     * @return array<string, mixed>
     */
    public static function receipt(CrossBorderTransfer $transfer): array
    {
        $transfer->loadMissing(['merchant', 'events', 'fundingTransaction.paymentProvider']);
        $paid = in_array($transfer->status, [CrossBorderStatus::FUNDED, CrossBorderStatus::PAYING_OUT, CrossBorderStatus::DELIVERED], true);

        return [
            'reference' => $transfer->reference,
            'merchant' => $transfer->merchant?->name,
            'status' => $transfer->status->value,
            'is_final' => $transfer->status->isFinal(),
            'headline' => match (true) {
                $transfer->status === CrossBorderStatus::DELIVERED => 'Payment complete',
                $paid => 'Payment received',
                $transfer->status === CrossBorderStatus::AWAITING_PAYMENT => 'Waiting for your PayPal payment',
                $transfer->status === CrossBorderStatus::PAYMENT_FAILED => 'Payment not completed',
                $transfer->status === CrossBorderStatus::REFUNDED => 'Refunded to your PayPal account',
                default => 'We couldn\'t complete this payment; it will be refunded',
            },
            'detail' => self::receiptDetail($transfer),
            'steps' => self::receiptSteps($transfer),
            'number' => strtoupper(substr(str_replace('-', '', $transfer->reference), 0, 10)),
            'paid' => ['amount' => (string) $transfer->sourceAmount(), 'currency' => $transfer->source_currency],
            // The merchant's price; its commission to the aggregator is between them.
            'price' => ['amount' => (string) Currency::fromMinorUnits($transfer->target_amount_minor + $transfer->commission_minor, $transfer->target_currency), 'currency' => $transfer->target_currency],
            'created_at' => $transfer->created_at?->toIso8601String(),
            // PayPal's own record of the payment, once it's captured.
            'paypal' => $transfer->fundingTransaction ? PaypalReceipt::for($transfer->fundingTransaction) : null,
        ];
    }

    /**
     * What's happening, in a sentence the customer can act on.
     */
    private static function receiptDetail(CrossBorderTransfer $transfer): string
    {
        $merchant = $transfer->merchant?->name ?? 'The merchant';

        return match ($transfer->status) {
            CrossBorderStatus::AWAITING_PAYMENT => 'Approve the payment in PayPal to continue.',
            CrossBorderStatus::FUNDED, CrossBorderStatus::PAYING_OUT => "PayPal has your payment. {$merchant} is being paid to its mobile-money wallet, and this page updates as soon as it's done.",
            CrossBorderStatus::DELIVERED => "{$merchant} has been paid to its mobile-money wallet.",
            CrossBorderStatus::PAYMENT_FAILED => 'The PayPal payment wasn\'t completed, so no money was taken.',
            CrossBorderStatus::PAYOUT_FAILED, CrossBorderStatus::REFUNDING => "{$merchant} couldn't be paid, so your payment is being refunded to your PayPal account.",
            CrossBorderStatus::REFUNDED => "{$merchant} couldn't be paid, so your payment was refunded in full to your PayPal account.",
            CrossBorderStatus::REFUND_FAILED => "{$merchant} couldn't be paid and the refund needs attention. {$merchant} has been told; keep this receipt.",
        };
    }

    /**
     * The payment's journey, step by step: paid with PayPal, then the
     * merchant paid (or the customer refunded).
     *
     * @return list<array{label: string, state: 'done'|'current'|'upcoming'|'failed'}>
     */
    private static function receiptSteps(CrossBorderTransfer $transfer): array
    {
        $merchant = $transfer->merchant?->name ?? 'the merchant';
        $status = $transfer->status;
        $refundPath = in_array($status, [CrossBorderStatus::PAYOUT_FAILED, CrossBorderStatus::REFUNDING, CrossBorderStatus::REFUNDED, CrossBorderStatus::REFUND_FAILED], true);

        $paid = match ($status) {
            CrossBorderStatus::AWAITING_PAYMENT => 'current',
            CrossBorderStatus::PAYMENT_FAILED => 'failed',
            default => 'done',
        };

        $sending = match (true) {
            in_array($status, [CrossBorderStatus::FUNDED, CrossBorderStatus::PAYING_OUT], true) => 'current',
            $status === CrossBorderStatus::DELIVERED => 'done',
            $refundPath => 'failed',
            default => 'upcoming',
        };

        $last = $refundPath
            ? ['label' => 'Refunded to you', 'state' => match ($status) {
                CrossBorderStatus::REFUNDED => 'done',
                CrossBorderStatus::REFUND_FAILED => 'failed',
                default => 'current',
            }]
            : ['label' => ucfirst($merchant).' paid', 'state' => $status === CrossBorderStatus::DELIVERED ? 'done' : 'upcoming'];

        return [
            ['label' => 'Paid with PayPal', 'state' => $paid],
            ['label' => 'Sending to '.$merchant, 'state' => $sending],
            $last,
        ];
    }
}
