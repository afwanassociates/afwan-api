<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\StepStatus;
use App\Models\PassportStepRecord;
use App\Support\Workflow;

/**
 * PATCH: fields not sent keep the record's current values, and the result is
 * validated as a whole (e.g. moving to completed needs a reference number).
 */
class UpdateStepRecordRequest extends StoreStepRecordRequest
{
    /**
     * Fill in the record's current values for the fields not sent.
     */
    protected function prepareForValidation(): void
    {
        $record = $this->record();
        $current = [
            'status' => $record->status->value,
            'remarks' => $record->remarks,
            'reference_no' => $record->reference_no,
            'step_date' => $record->step_date->toDateString(),
            'valid_until' => $record->valid_until?->toDateString(),
        ];
        $input = $this->all();

        foreach ($current as $key => $value) {
            if (! array_key_exists($key, $input)) {
                $this->merge([$key => $value]);
            }
        }

        // An older completed record dated by its result field (visa issued
        // before the issue date was asked for) has that date as its own.
        $resultDateFrom = app(Workflow::class)->step($record->step_key)['result_date_from'] ?? null;
        $existing = $record->details ?? [];

        if ($resultDateFrom !== null && $record->status === StepStatus::Completed && ($existing[$resultDateFrom] ?? null) === null) {
            $existing[$resultDateFrom] = $record->step_date->toDateString();
        }

        $details = $this->input('details');
        $this->merge(['details' => is_array($details) || $details === null
            ? array_merge($existing, $details ?? [])
            : $details]);
    }

    /**
     * The step of the record being edited.
     */
    public function stepKey(): string
    {
        return $this->record()->step_key;
    }

    public function record(): PassportStepRecord
    {
        return $this->route('stepRecord');
    }
}
