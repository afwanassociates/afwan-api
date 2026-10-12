<?php

namespace App\Http\Resources;

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

        // The step's list_hidden fields (calling: entry_mode and step_date).
        foreach (['record', 'previous_record', 'next_record'] as $key) {
            if (is_array($row[$key]) && $row[$key]['step_key'] === $this->step) {
                $row[$key] = array_diff_key($row[$key], array_flip($hidden));
            }
        }

        return array_diff_key($row, array_flip($hidden));
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
