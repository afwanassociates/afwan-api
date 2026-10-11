<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PassportEntry>
 */
class PassportEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'passport_name' => fake()->name(),
            'passport_number' => fake()->unique()->bothify('??#######'),
            'country_code' => 'BD',
            'date_of_birth' => fake()->dateTimeBetween('-50 years', '-18 years'),
            'reference_id' => Reference::factory(),
            'company_id' => Company::factory(),
            'passport_received_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'passport_expiry_date' => fake()->dateTimeBetween('+1 year', '+10 years'),
            // Scan details only (no file on the disk): new passports always have a
            // scan, so editing them needs no upload. Use withoutScan() for old rows.
            'scan_path' => fn () => 'passport-scans/factory/'.Str::uuid().'.jpg',
            'scan_original_name' => 'passport.jpg',
            'scan_mime' => 'image/jpeg',
            'scan_size' => 102400,
            'scan_uploaded_at' => now(),
        ];
    }

    /**
     * Configure the model factory: like the app, set the stored workflow
     * position (stage_status_date) after creating.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (PassportEntry $entry) {
            app(WorkflowService::class)->recompute($entry);
        });
    }

    /**
     * Indicate that no scan copy was uploaded (as on passports entered
     * before scans were required).
     */
    public function withoutScan(): static
    {
        return $this->state(fn (array $attributes) => [
            'scan_path' => null,
            'scan_original_name' => null,
            'scan_mime' => null,
            'scan_size' => null,
            'scan_uploaded_at' => null,
        ]);
    }

    /**
     * Indicate that the medical slip date (MYGRAM) is entered, so the medical
     * is pending until a result is recorded.
     */
    public function withMedicalSlip(string $date = '2026-01-20'): static
    {
        return $this->state(fn (array $attributes) => [
            'medical_slip_date' => $date,
        ]);
    }

    /**
     * Indicate that the entry was created (and last updated) by the given user.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
