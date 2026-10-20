<?php

namespace Database\Factories;

use App\Enums\BalanceType;
use App\Enums\PartyType;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => PartyType::Client,
            'name' => fake()->name(),
            'mobile' => '017'.fake()->numerify('########'),
            'address' => fake()->address(),
            'nid_passport' => fake()->unique()->numerify('##########'),
            'tin_bin' => null,
            'opening_balance' => 0,
            'opening_balance_type' => BalanceType::Receivable,
            'is_active' => true,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes) => ['type' => PartyType::Owner]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
