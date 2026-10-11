<?php

namespace App\Http\Resources;

use App\Models\PassportEntry;
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
        $record = app(Workflow::class)->latestRecords($this->resource)->get($this->step);

        return [
            'id' => $this->id,
            'passport_name' => $this->passport_name,
            'passport_number' => $this->passport_number,
            'reference' => $this->reference ? ['id' => $this->reference->id, 'name' => $this->reference->name] : null,
            'company' => ['id' => $this->company->id, 'name' => $this->company->name],
            'record' => $record ? [
                'id' => $record->id,
                'status' => $record->status->value,
                'status_label' => $record->status->label($record->step_key),
                'step_date' => $record->step_date->toDateString(),
                'reference_no' => $record->reference_no,
                'valid_until' => $record->valid_until?->toDateString(),
                'details' => $record->details,
            ] : null,
            'previous_step_completed_on' => app(StepList::class)->previousCompletedOn($this->resource, $this->step),
        ];
    }
}
