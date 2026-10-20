<?php

namespace App\Http\Resources;

use App\Enums\StepStatus;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Support\StepList;
use App\Support\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row of a step's work list: the passport, its reference and company, this
 * step's latest record and when the step before was completed. Set the step
 * with forStep() (see StepListController).
 *
 * @mixin PassportEntry
 */
class StepListResource extends JsonResource
{
    /**
     * The step being listed.
     */
    protected string $step = '';

    /**
     * List this passport for the given step.
     */
    public function forStep(string $step): static
    {
        $this->step = $step;

        return $this;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workflow = app(Workflow::class);
        $latest = $workflow->latestRecords($this->resource);
        $record = $latest->get($this->step);
        $before = $workflow->recordStepsBefore($this->step);
        $previous = $before === [] ? null : $latest->get(end($before));
        $next = $latest->get($workflow->recordStepsAfter($this->step)[0] ?? '');

        $hidden = $workflow->step($this->step)['list_hidden'] ?? [];

        $row = [
            'id' => $this->id,
            'passport_name' => $this->passport_name,
            'passport_number' => $this->passport_number,
            'reference' => $this->reference ? ['id' => $this->reference->id, 'name' => $this->reference->name] : null,
            'company' => ['id' => $this->company->id, 'name' => $this->company->name],
            'entry_mode' => $this->entry_mode ? ['value' => $this->entry_mode->value, 'label' => $this->entry_mode->label()] : null,
            'record' => $record ? $this->summary($record) : null,
            // The step before (e.g. the calling dates on a visa row) and after
            // (e.g. the visa on a calling row), when they have a record.
            'previous_record' => $previous ? $this->summary($previous) : null,
            'next_record' => $next ? $this->summary($next) : null,
            'previous_step_completed_on' => app(StepList::class)->previousCompletedOn($this->resource, $this->step),
        ];

        // The step's list_extras (BMET and flight: the visa dates, the BMET
        // milestone dates, the flight date).
        $visa = $latest->get('visa');
        $bmet = $latest->get('bmet');
        $bmetDates = [
            'training_date' => $bmet?->detail('training_certificate_date'),
            'finger_date' => $bmet?->detail('finger_date'),
            'clearance_date' => $bmet?->status === StepStatus::Completed ? $workflow->completedOn($bmet) : null,
        ];

        foreach ($workflow->step($this->step)['list_extras'] ?? [] as $extra) {
            $row = [...$row, ...match ($extra) {
                'visa_issue_date' => [$extra => $visa?->status === StepStatus::Completed ? ($visa->detail('issue_date') ?? $workflow->completedOn($visa)) : null],
                'visa_valid_until' => [$extra => $visa?->valid_until?->toDateString()],
                // training_date, finger_date and clearance_date on the row itself.
                'bmet_dates' => $bmetDates,
                // BMET and the booking side by side, and whether Flight confirm can be done.
                'flight_status' => $this->flightStatus($record, $bmet, $bmetDates['clearance_date']),
                default => [$extra => null],
            }];
        }

        // The step's list_hidden fields (calling: entry_mode and step_date).
        foreach (['record', 'previous_record', 'next_record'] as $key) {
            if (is_array($row[$key]) && $row[$key]['step_key'] === $this->step) {
                $row[$key] = array_diff_key($row[$key], array_flip($hidden));
            }
        }

        return array_diff_key($row, array_flip($hidden));
    }

    /**
     * The flight row: BMET cleared (and when), the booking and confirmed
     * dates, and what is still missing before Flight confirm (bmet,
     * booking_date).
     *
     * @return array{bmet_cleared: bool, clearance_date: string|null, booking_date: string|null, confirmed_date: string|null, can_confirm: bool, missing: list<string>}
     */
    protected function flightStatus(?PassportStepRecord $flight, ?PassportStepRecord $bmet, ?string $clearanceDate): array
    {
        $confirmed = $flight?->status === StepStatus::Completed;
        $bookingDate = $flight?->detail('booking_date');
        $missing = $confirmed ? [] : [
            ...($bmet?->status === StepStatus::Completed ? [] : ['bmet']),
            ...($bookingDate === null ? ['booking_date'] : []),
        ];

        return [
            'bmet_cleared' => $bmet?->status === StepStatus::Completed,
            'clearance_date' => $clearanceDate,
            'booking_date' => $bookingDate,
            'confirmed_date' => $confirmed ? ($flight->detail('confirmed_date') ?? $flight->step_date->toDateString()) : null,
            'can_confirm' => ! $confirmed && $missing === [],
            'missing' => $missing,
        ];
    }

    /**
     * A step record as the step pages show it.
     *
     * @return array<string, mixed>
     */
    protected function summary(PassportStepRecord $record): array
    {
        return [
            'id' => $record->id,
            'step_key' => $record->step_key,
            'status' => $record->status->value,
            'status_label' => $record->status->label($record->step_key),
            'step_date' => $record->step_date->toDateString(),
            // When the step was started (e.g. the visa submitted date); the
            // record's date becomes its result date once it has one.
            'submitted_date' => $record->detail('start_date') ?? $record->step_date->toDateString(),
            'reference_no' => $record->reference_no,
            // Calling: the permit's receive and issued dates (null on old records).
            'receive_date' => $record->detail('receive_date'),
            'issue_date' => $record->detail('issue_date'),
            'valid_until' => $record->valid_until?->toDateString(),
            'details' => $record->details,
        ];
    }
}
