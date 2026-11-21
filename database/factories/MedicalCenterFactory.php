<?php

namespace Database\Factories;

use App\Models\MedicalCenter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalCenter>
 */
class MedicalCenterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->lastName().' Diagnostic Centre',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the centre is deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
