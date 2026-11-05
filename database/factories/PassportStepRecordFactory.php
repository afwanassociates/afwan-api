<?php

namespace Database\Factories;

use App\Enums\StepStatus;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\LocalDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test data only: application code records steps through WorkflowService.
 * Like the service, it recomputes the passport's stage after creating (but it
 * does not check the step order: set records up in order).
 *
 * @extends Factory<PassportStepRecord>
 */
class PassportStepRecordFactory extends Factory
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
            'step_key' => 'calling',
            'status' => StepStatus::Completed,
            'reference_no' => fake()->bothify('REF-#####'),
            'step_date' => LocalDate::today()->subDays(fake()->numberBetween(1, 30))->toDateString(),
            'valid_until' => null,
            'details' => null,
            'remarks' => null,
            'created_by' => User::factory()->dataEntry(),
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (PassportStepRecord $record) {
            app(WorkflowService::class)->recompute($record->passportEntry()->withTrashed()->firstOrFail());
        });
    }

    /**
     * Indicate the step the record belongs to.
     */
    public function step(string $key): static
    {
        return $this->state(fn (array $attributes) => [
            'step_key' => $key,
        ]);
    }

    /**
     * Indicate the record's status.
     */
    public function status(StepStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    /**
     * Indicate the record's step date (Y-m-d).
     */
    public function on(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'step_date' => $date,
        ]);
    }

    /**
     * Indicate the date the record (e.g. a visa) is valid until (Y-m-d).
     */
    public function validUntil(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'valid_until' => $date,
        ]);
    }

    /**
     * A flight record booked for $bookingDate (Y-m-d), dated by it; a
     * completed one is confirmed on $confirmedDate (the booking date when
     * not given) and dated by that. Call it after status().
     */
    public function flight(string $bookingDate, ?string $confirmedDate = null): static
    {
        return $this->state(function (array $attributes) use ($bookingDate, $confirmedDate) {
            $confirmed = $attributes['status'] === StepStatus::Completed ? ($confirmedDate ?? $bookingDate) : null;

            return [
                'step_key' => 'flight',
                'reference_no' => null,
                'step_date' => $confirmed ?? $bookingDate,
                'details' => array_filter(['booking_date' => $bookingDate, 'confirmed_date' => $confirmed]),
            ];
        });
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
