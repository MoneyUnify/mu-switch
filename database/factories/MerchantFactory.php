<?php

namespace Database\Factories;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Merchant>
 */
class MerchantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'slug' => Merchant::uniqueSlug($name),
            'email' => fake()->companyEmail(),
            'country' => 'ZM',
            'currency' => 'ZMW',
            'wallet_number' => '260977'.fake()->numerify('######'),
            'wallet_name' => $name,
            'network' => null,
            'commission_percent' => 2.00,
            'is_active' => true,
        ];
    }

    /**
     * A merchant that no longer accepts payments.
     */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
