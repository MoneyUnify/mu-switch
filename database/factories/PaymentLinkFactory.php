<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\PaymentLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentLink>
 */
class PaymentLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'merchant_id' => Merchant::factory(),
            'code' => PaymentLink::newCode(),
            'label' => fake()->words(2, true),
            'pay_currencies' => ['USD'],
            'settle_currency' => 'ZMW',
            'amount_minor' => null,
            'reference' => null,
            'is_active' => true,
        ];
    }

    /**
     * A link for a fixed price, in minor units of the settlement currency.
     */
    public function fixed(int $amountMinor): static
    {
        return $this->state(fn (): array => ['amount_minor' => $amountMinor]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
