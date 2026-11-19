<?php

namespace App\Support\Merchants;

use App\Contracts\PayoutProviderInterface;
use App\Exceptions\NoEligibleProviderException;
use App\Models\Merchant;
use App\Models\User;
use App\Support\Market;
use App\Support\ProviderRouter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Onboards and updates an aggregator's merchants, shared by the API and the
 * dashboard.
 */
class MerchantRegistry
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'country' => [$required, 'string', 'size:2', Rule::in(Market::codes())],
            'wallet_number' => [$required, 'string', 'max:50'],
            'wallet_name' => ['nullable', 'string', 'max:120'],
            'network' => ['nullable', 'string', 'max:50'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:20', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Create or update a merchant. Its wallet must be in a country one of the
     * account's payout providers can pay, or it could never be settled.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function save(User $user, array $data, ?Merchant $merchant = null): Merchant
    {
        $country = strtoupper((string) ($data['country'] ?? $merchant?->country));
        $currency = Market::currency($country);

        if ($merchant === null || isset($data['country'])) {
            try {
                ProviderRouter::route($user, $country, (string) $currency, 1, PayoutProviderInterface::class);
            } catch (NoEligibleProviderException) {
                throw ValidationException::withMessages(['country' => 'None of your payout providers can settle to '.Market::name($country).'. Add one on the Providers page first.']);
            }
        }

        $attributes = array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'country' => $country,
            'currency' => $currency,
            'wallet_number' => isset($data['wallet_number']) ? Market::msisdn((string) $data['wallet_number'], $country) : null,
            'wallet_name' => $data['wallet_name'] ?? null,
            'network' => isset($data['network']) ? strtolower(trim((string) $data['network'])) ?: null : null,
            'commission_percent' => $data['commission_percent'] ?? null,
            'is_active' => $data['is_active'] ?? null,
        ], fn ($value): bool => $value !== null);

        if ($merchant) {
            $merchant->update($attributes);

            return $merchant->refresh();
        }

        return $user->merchants()->create([
            'commission_percent' => 0,
            ...$attributes,
            'slug' => Merchant::uniqueSlug((string) $data['name']),
        ]);
    }
}
