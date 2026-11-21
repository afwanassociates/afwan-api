<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\StepStatus;
use App\Exceptions\WorkflowException;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Support\LocalDate;
use App\Support\Workflow;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * Record a step's outcome on its in_process record: result (completed or
 * rejected, labelled per step in config/workflow.php), result_date (today when
 * not sent) and the step's "result" fields. The record's start fields are
 * kept; the whole record is validated with its new status.
 */
class StepResultRequest extends StoreStepRecordRequest
{
    protected ?PassportStepRecord $inProcessRecord = null;

    /**
     * Build the record as it will be: its current values, with the result,
     * result date and result-phase fields from the request.
     *
     * @throws WorkflowException when the step has no in_process record
     */
    protected function prepareForValidation(): void
    {
        $record = $this->record();
        $input = $this->all();

        $data = [
            // A step with one outcome (calling: permitted) needs no result value.
            'status' => $this->input('result', count($this->outcomes()) === 1 ? $this->outcomes()[0] : null),
            'step_date' => $this->input('result_date') ?? LocalDate::today()->toDateString(),
            'remarks' => array_key_exists('remarks', $input) ? $input['remarks'] : $record->remarks,
            'reference_no' => $record->reference_no,
            'valid_until' => $record->valid_until?->toDateString(),
        ];
        $details = $record->details ?? [];

        foreach (parent::fields() as $field) {
            if (($field['phase'] ?? 'start') !== 'result') {
                continue;
            }

            $key = $this->key($field);
            // A details field may also be sent at the top level (receive_date).
            $sentAs = Arr::has($input, $key) ? $key : (Arr::has($input, $field['name']) ? $field['name'] : null);

            if ($sentAs === null) {
                continue;
            }

            if ($field['in_details'] ?? false) {
                $details[$field['name']] = data_get($input, $sentAs);
            } else {
                $data[$field['name']] = data_get($input, $sentAs);
            }
        }

        $this->merge([...$data, 'details' => $details]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'result' => [count($this->outcomes()) === 1 ? 'sometimes' : 'required', Rule::in($this->outcomes())],
            'result_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.LocalDate::today()->toDateString()],
        ];
    }

    /**
     * The step's outcomes: its statuses other than in_process (calling has
     * only completed; the other steps completed and rejected).
     *
     * @return list<string>
     */
    public function outcomes(): array
    {
        return array_values(array_diff(
            array_keys(app(Workflow::class)->step($this->stepKey())['statuses'] ?? []),
            [StepStatus::InProcess->value],
        ));
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $labels = app(Workflow::class)->step($this->stepKey())['statuses'] ?? [];
        $outcomes = array_map(fn (string $outcome) => $labels[$outcome] ?? $outcome, $this->outcomes());

        return [
            ...parent::messages(),
            'result.in' => 'The result must be '.implode(' or ', $outcomes).'.',
            'status.in' => 'The result must be '.implode(' or ', $outcomes).'.',
            'result_date.before_or_equal' => 'The result date cannot be in the future.',
            'step_date.before_or_equal' => 'The result date cannot be in the future.',
        ];
    }

    public function stepKey(): string
    {
        return (string) $this->route('step');
    }

    /**
     * The step's latest record, which must be in process.
     *
     * @throws WorkflowException
     */
    public function record(): PassportStepRecord
    {
        if ($this->inProcessRecord !== null) {
            return $this->inProcessRecord;
        }

        /** @var PassportEntry $passport */
        $passport = $this->route('passport');

        $record = PassportStepRecord::query()
            ->where('passport_entry_id', $passport->id)
            ->where('step_key', $this->stepKey())
            ->orderByDesc('step_date')
            ->orderByDesc('id')
            ->first();

        if ($record?->status !== StepStatus::InProcess) {
            throw WorkflowException::stepNotStarted(app(Workflow::class)->label($this->stepKey()));
        }

        return $this->inProcessRecord = $record;
    }
}
