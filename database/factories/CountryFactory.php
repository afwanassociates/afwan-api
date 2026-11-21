<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Real countries come from the migration; the factory makes extra ones with
 * user-assigned codes (AA, QM-QZ, XA-XZ except XK, ZZ) so they never clash.
 *
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = fake()->unique()->randomElement([
            'AA', 'ZZ',
            ...array_map(fn (string $letter) => 'Q'.$letter, range('M', 'Z')),
            ...array_map(fn (string $letter) => 'X'.$letter, array_diff(range('A', 'Z'), ['K'])),
        ]);

        return [
            'code' => $code,
            'name' => "Testland {$code}",
            'is_active' => true,
            'is_pinned' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Indicate that the country is deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
