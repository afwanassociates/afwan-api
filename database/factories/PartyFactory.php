<?php

namespace Database\Factories;

use App\Enums\BalanceType;
use App\Enums\OwnerDesignation;
use App\Enums\PartyKind;
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
            'kind' => PartyKind::Agency,
            'name' => fake()->unique()->company().' Agency',
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
        return $this->state(fn (array $attributes) => ['type' => PartyType::Owner, 'kind' => null, 'name' => fake()->name(), 'designation' => OwnerDesignation::Director]);
    }

    /**
     * A client agency, as quick-added from the passport form: name and phone only.
     */
    public function agency(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PartyType::Client,
            'kind' => PartyKind::Agency,
            'name' => fake()->unique()->company().' Agency',
        ]);
    }

    /**
     * A client person (no RL number).
     */
    public function person(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => PartyType::Client,
            'kind' => PartyKind::Person,
            'name' => fake()->unique()->name(),
            'rl_number' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }
}
