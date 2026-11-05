<?php

namespace Database\Factories;

use App\Enums\MedicalResult;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;
use App\Services\MedicalService;
use App\Services\WorkflowService;
use App\Support\LocalDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test data only: application code records medicals through MedicalService.
 * Like the service, it derives valid_until and refreshes the passport's
 * current_medical_id and stage_status_date after creating.
 *
 * @extends Factory<MedicalRecord>
 */
class MedicalRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'passport_entry_id' => PassportEntry::factory(),
            'medical_date' => LocalDate::today()->subDays(fake()->numberBetween(1, 30))->toDateString(),
            'result' => MedicalResult::Fit,
            'valid_until' => fn (array $attributes) => MedicalService::validUntil(
                $attributes['medical_date'],
                $attributes['result'] instanceof MedicalResult ? $attributes['result'] : MedicalResult::from($attributes['result']),
            ),
            'issue_date' => fn (array $attributes) => $attributes['medical_date'],
            'remarks' => null,
            'created_by' => User::factory()->dataEntry(),
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (MedicalRecord $record) {
            app(WorkflowService::class)->recompute($record->passportEntry()->withTrashed()->firstOrFail());
        });
    }

    /**
     * Indicate that the medical took place on the given date (Y-m-d).
     */
    public function on(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'medical_date' => $date,
        ]);
    }

    /**
     * Indicate that the result is fit.
     */
    public function fit(): static
    {
        return $this->state(fn (array $attributes) => [
            'result' => MedicalResult::Fit,
        ]);
    }

    /**
     * Indicate that the result is unfit.
     */
    public function unfit(): static
    {
        return $this->state(fn (array $attributes) => [
            'result' => MedicalResult::Unfit,
        ]);
    }

    /**
     * Indicate that a fit result is valid until the given date (Y-m-d), by
     * back-dating the medical date accordingly.
     */
    public function validUntil(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'result' => MedicalResult::Fit,
            'medical_date' => LocalDate::parse($date)->subMonthsNoOverflow((int) config('medical.validity_months'))->toDateString(),
            'valid_until' => $date,
        ]);
    }

    /**
     * Indicate that the record was entered by the given user.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }
}
