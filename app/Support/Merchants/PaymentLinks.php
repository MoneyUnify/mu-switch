<?php

namespace App\Support\Merchants;

use App\Enums\CrossBorderStatus;
use App\Models\CrossBorderTransfer;
use App\Models\Merchant;
use App\Models\PaymentLink;
use App\Support\Currency;
use App\Support\Fees\FeeCalculator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates, changes and removes a merchant's pay links, shared by the
 * dashboard and the API.
 */
class PaymentLinks
{
    public function __construct(private readonly MerchantCurrencies $currencies) {}

    /**
     * @return array<string, mixed>
     */
    public function rules(Merchant $merchant, bool $updating = false): array
    {
        return [
            'label' => ['nullable', 'string', 'max:120'],
            'pay_currencies' => ['nullable', 'array'],
            'pay_currencies.*' => ['string', Rule::in($this->currencies->payCurrencies($merchant->user))],
            'settle_currency' => [$updating ? 'sometimes' : 'nullable', 'string', Rule::in($this->currencies->settlementCurrencies($merchant->user, $merchant->country))],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(Merchant $merchant, array $data): PaymentLink
    {
        return $merchant->paymentLinks()->create([
            ...$this->attributes($merchant, $data, null),
            'code' => PaymentLink::newCode(),
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(PaymentLink $link, array $data): PaymentLink
    {
        $link->update([
            ...$this->attributes($link->merchant, $data, $link),
            ...(array_key_exists('is_active', $data) ? ['is_active' => (bool) $data['is_active']] : []),
        ]);

        return $link->refresh();
    }

    /**
     * Remove a link. One that took payments is archived (it stops working
     * and leaves the list, but its payments keep their history); one that
     * never did is deleted outright.
     *
     * @return 'archived'|'deleted'
     */
    public function delete(PaymentLink $link): string
    {
        if ($link->payments()->exists()) {
            $link->update(['is_active' => false]);
            $link->delete();

            return 'archived';
        }

        $link->forceDelete();

        return 'deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(PaymentLink $link): array
    {
        $payments = $link->payments()->get(['status', 'target_amount_minor', 'commission_minor', 'target_currency']);
        $delivered = $payments->where('status', CrossBorderStatus::DELIVERED);

        return [
            'id' => $link->id,
            'code' => $link->code,
            'label' => $link->label,
            'url' => $link->url(),
            'pay_currencies' => $link->pay_currencies,
            'settle_currency' => $link->settle_currency,
            'amount' => $link->amount(),
            'reference' => $link->reference,
            'is_active' => $link->is_active,
            'created_at' => $link->created_at?->toIso8601String(),
            'stats' => [
                'payments' => $delivered->count(),
                'in_progress' => $payments->filter(fn (CrossBorderTransfer $payment): bool => ! $payment->status->isFinal())->count(),
                'settled' => (string) Currency::fromMinorUnits((int) $delivered->where('target_currency', $link->settle_currency)->sum('target_amount_minor'), $link->settle_currency),
                'has_payments' => $payments->isNotEmpty(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function attributes(Merchant $merchant, array $data, ?PaymentLink $link): array
    {
        $settle = strtoupper((string) ($data['settle_currency'] ?? $link?->settle_currency ?? $merchant->currency));
        $attributes = [];

        if (array_key_exists('label', $data)) {
            $attributes['label'] = $data['label'] ?: null;
        }

        if (array_key_exists('pay_currencies', $data) || $link === null) {
            $chosen = array_values(array_unique(array_map('strtoupper', (array) ($data['pay_currencies'] ?? []))));
            $all = $this->currencies->payCurrencies($merchant->user);
            sort($chosen);
            // Choosing none, or all of them, means "any currency".
            $attributes['pay_currencies'] = $chosen === [] || $chosen === $all ? null : $chosen;
        }

        $attributes['settle_currency'] = $settle;

        if (array_key_exists('amount', $data) || $link === null) {
            $attributes['amount_minor'] = $this->minor($data['amount'] ?? null, $settle);
        } elseif ($link->amount_minor !== null && $settle !== $link->settle_currency) {
            // Same price, re-expressed in the new currency's minor units.
            $attributes['amount_minor'] = $this->minor($link->amount(), $settle);
        }

        if (array_key_exists('reference', $data)) {
            $attributes['reference'] = $data['reference'] ?: null;
        }

        return $attributes;
    }

    /**
     * @throws ValidationException
     */
    private function minor(mixed $amount, string $currency): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $value = FeeCalculator::money((string) $amount);
        $decimals = Currency::decimals($currency);

        if ($value->strippedOfTrailingZeros()->getScale() > $decimals) {
            throw ValidationException::withMessages(['amount' => "{$currency} amounts can have at most {$decimals} decimal places."]);
        }

        return Currency::toMinorUnits($value, $currency);
    }
}
