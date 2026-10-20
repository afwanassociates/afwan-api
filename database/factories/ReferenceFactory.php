<?php

namespace Database\Factories;

use App\Enums\ReferenceType;
use App\Models\Reference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reference>
 */
class ReferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(ReferenceType::cases());

        return [
            'type' => $type,
            'name' => $type === ReferenceType::Agency
                ? fake()->unique()->company().' Agency'
                : fake()->unique()->name(),
            'phone' => fake()->optional()->numerify('01#########'),
            'notes' => null,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the reference is a person.
     */
    public function person(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ReferenceType::Person,
            'name' => fake()->unique()->name(),
        ]);
    }

    /**
     * Indicate that the reference is an agency.
     */
    public function agency(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ReferenceType::Agency,
            'name' => fake()->unique()->company().' Agency',
        ]);
    }

    /**
     * Indicate that the reference is deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
