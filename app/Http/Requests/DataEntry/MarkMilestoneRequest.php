<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\StepStatus;
use App\Models\PassportEntry;
use App\Support\LocalDate;
use App\Support\Workflow;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Mark a milestone step's action done (BMET: training, finger, clearance):
 * an optional date, today in Dhaka when not sent, never in the future. The
 * complete action (clearance) cannot be dated before a milestone.
 */
class MarkMilestoneRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.LocalDate::today()->toDateString()],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.before_or_equal' => 'The date cannot be in the future.',
        ];
    }

    /**
     * The complete action cannot be before a milestone date.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $workflow = app(Workflow::class);
                $step = $this->stepKey();

                if ($validator->errors()->has('date') || $workflow->milestone($step, $this->action()) !== null) {
                    return;
                }

                /** @var PassportEntry $passport */
                $passport = $this->route('passport');
                $record = $workflow->latestRecords($passport->load('stepRecords'))->get($step);

                if ($record === null || $record->status === StepStatus::Completed) {
                    return;
                }

                $dates = array_filter(array_map(fn (array $milestone) => $record->detail($milestone['field']), $workflow->step($step)['milestones']));

                if ($dates !== [] && $this->actionDate() < max($dates)) {
                    $label = strtolower($workflow->step($step)['complete_action']['label']);
                    $validator->errors()->add('date', "The {$label} date cannot be before the training or finger date (".date('d-m-Y', strtotime(max($dates))).').');
                }
            },
        ];
    }

    /**
     * The date the action was done (Y-m-d): the one sent or today in Dhaka.
     */
    public function actionDate(): string
    {
        return $this->input('date') ?: LocalDate::today()->toDateString();
    }

    public function stepKey(): string
    {
        return (string) $this->route('step');
    }

    public function action(): string
    {
        return (string) $this->route('action');
    }
}
