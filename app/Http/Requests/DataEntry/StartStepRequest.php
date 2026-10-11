<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\StepStatus;

/**
 * Start (apply for) a step: its date plus the step's "start" fields from
 * config/workflow.php. Always creates an in_process record.
 */
class StartStepRequest extends StoreStepRecordRequest
{
    /**
     * A started step is in process, whatever the request says.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['status' => StepStatus::InProcess->value]);
    }

    public function stepKey(): string
    {
        return (string) $this->route('step');
    }

    /**
     * Only the step's start-phase fields.
     *
     * @return list<array<string, mixed>>
     */
    protected function fields(): array
    {
        return array_values(array_filter(parent::fields(), fn (array $field) => ($field['phase'] ?? 'start') === 'start'));
    }
}
