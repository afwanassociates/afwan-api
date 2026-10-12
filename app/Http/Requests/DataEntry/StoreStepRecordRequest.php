<?php

namespace App\Http\Requests\DataEntry;

use App\Support\LocalDate;
use App\Support\Workflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rules come from the step's fields in config/workflow.php. Only status,
 * remarks and the step's own fields are accepted; created_by, updated_by,
 * deleted_by and unknown details keys are never taken from the request.
 */
class StoreStepRecordRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // "Today" is the Dhaka calendar date, not UTC's.
        $today = LocalDate::today();
        $status = $this->input('status');

        $rules = [
            // Only the statuses this step has (calling has no rejected).
            'status' => ['required', Rule::in($this->allowedStatuses())],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'details' => ['nullable', 'array'],
        ];

        foreach ($this->fields() as $field) {
            $required = $field['required_for'] ?? [];
            $notFutureFor = $field['not_future_for'] ?? [];

            $fieldRules = [
                $required === [] ? 'nullable' : 'required_if:status,'.implode(',', $required),
                'nullable',
                ...match ($field['type']) {
                    'date' => ['date_format:Y-m-d'],
                    default => ['string', 'max:'.($field['max'] ?? 255)],
                },
                ...($field['rules'] ?? []),
            ];

            if (is_string($status) && in_array($status, $notFutureFor, true)) {
                $fieldRules[] = 'before_or_equal:'.$today->toDateString();
            }

            $fieldRules = [...$fieldRules, ...match ($field['name']) {
                'step_date' => ['before_or_equal:'.$today->toDateString()],
                'valid_until' => ['after:'.($field['after'] ?? 'step_date'), 'before_or_equal:'.$today->addYears(5)->toDateString()],
                default => [],
            }];

            $rules[$this->key($field)] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'step_date.before_or_equal' => 'The date cannot be in the future.',
            'valid_until.after' => $this->validUntilAfterMessage(),
            'status.in' => 'This status is not available for this step.',
            'details.receive_date.after_or_equal' => 'The calling receive date cannot be before the calling date.',
            'details.receive_date.before_or_equal' => 'The calling receive date cannot be in the future.',
            'details.issue_date.before_or_equal' => 'The issued date cannot be in the future.',
            'valid_until.before_or_equal' => 'The valid until date cannot be more than 5 years ahead.',
            'details.departure_date.before_or_equal' => 'A flight can only be marked departed on or after its departure date.',
            'details.training_certificate_date.before_or_equal' => 'The training certificate date cannot be in the future.',
            'details.finger_date.before_or_equal' => 'The finger date cannot be in the future.',
            'details.flight_no.regex' => 'The flight number may only contain letters and digits.',
        ];
    }

    /**
     * "must be after the ..." naming the field valid_until follows.
     */
    protected function validUntilAfterMessage(): string
    {
        $fields = collect($this->fields());
        $validUntil = $fields->firstWhere('name', 'valid_until');
        $after = $validUntil['after'] ?? 'step_date';
        $afterField = $fields->first(fn (array $field) => $this->key($field) === $after);
        $label = strtolower($validUntil['label'] ?? 'valid until date');

        return "The {$label} must be after the ".strtolower($afterField['label'] ?? 'date').'.';
    }

    /**
     * Get custom attributes for validator errors, from the field labels.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach ($this->fields() as $field) {
            $attributes[$this->key($field)] = strtolower($field['label']);
        }

        return $attributes;
    }

    /**
     * The validated values for the step record: columns, plus only the
     * step's own details keys (null when the step has none).
     *
     * @return array<string, mixed>
     */
    public function recordData(): array
    {
        $validated = $this->validated();
        $data = [
            'status' => $validated['status'],
            'remarks' => $validated['remarks'] ?? null,
        ];
        $details = [];

        foreach ($this->fields() as $field) {
            $value = data_get($validated, $this->key($field));

            if ($field['in_details'] ?? false) {
                if ($value !== null) {
                    $details[$field['name']] = $value;
                }
            } else {
                $data[$field['name']] = $value;
            }
        }

        $data['details'] = $details === [] ? null : $details;

        // A step without a date field of its own takes it from another field
        // (calling: the receive date).
        $dateFrom = app(Workflow::class)->step($this->stepKey())['date_from'] ?? null;

        if ($dateFrom !== null && ! array_key_exists('step_date', $data) && ($data[$dateFrom] ?? $details[$dateFrom] ?? null) !== null) {
            $data['step_date'] = $data[$dateFrom] ?? $details[$dateFrom];
        }

        return $data;
    }

    /**
     * The statuses a record may get. A single-update step (calling) creates
     * records completed only; in_process stays for its older records.
     *
     * @return list<string>
     */
    protected function allowedStatuses(): array
    {
        $step = app(Workflow::class)->step($this->stepKey());
        $statuses = array_keys($step['statuses'] ?? []);

        return ($step['single_update'] ?? false) && ! $this instanceof UpdateStepRecordRequest
            ? array_values(array_diff($statuses, ['in_process']))
            : $statuses;
    }

    /**
     * The step being recorded.
     */
    public function stepKey(): string
    {
        return (string) $this->route('stepKey');
    }

    /**
     * The step's fields from config/workflow.php.
     *
     * @return list<array<string, mixed>>
     */
    protected function fields(): array
    {
        return app(Workflow::class)->step($this->stepKey())['fields'] ?? [];
    }

    /**
     * The request key of a field: "details.<name>" for details fields.
     *
     * @param  array<string, mixed>  $field
     */
    protected function key(array $field): string
    {
        return ($field['in_details'] ?? false) ? "details.{$field['name']}" : $field['name'];
    }
}
