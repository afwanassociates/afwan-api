<?php

namespace App\Http\Requests\DataEntry;

use App\Services\StepBulkService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One action for many passports on a step page. `data` is the body the
 * single action takes (each passport is validated with that action's own
 * rules by App\Services\StepBulkService).
 */
class BulkStepRequest extends FormRequest
{
    /** Passports per bulk action. */
    public const MAX_PASSPORTS = 100;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(app(StepBulkService::class)->actionsFor((string) $this->route('step')))],
            'passport_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PASSPORTS],
            'passport_ids.*' => ['integer', 'distinct', Rule::exists('passport_entries', 'id')->whereNull('deleted_at')],
            'data' => ['sometimes', 'array'],
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
            'action.in' => 'This step has no such bulk action.',
            'passport_ids.required' => 'Select at least one passport.',
            'passport_ids.max' => 'Select at most '.self::MAX_PASSPORTS.' passports at a time.',
            'passport_ids.*.exists' => 'One of the selected passports no longer exists.',
        ];
    }
}
