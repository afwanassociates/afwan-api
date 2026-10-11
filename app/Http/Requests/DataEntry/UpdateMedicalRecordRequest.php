<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\MedicalResult;
use App\Support\LocalDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correct a recorded medical result (PATCH; every field optional). Only
 * medical_date, result and remarks are accepted: valid_until and the tracking
 * columns are always derived in App\Services\MedicalService.
 */
class UpdateMedicalRecordRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        // "Today" is the Dhaka calendar date, not UTC's.
        $today = LocalDate::today();

        return [
            'medical_date' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                'before_or_equal:'.$today->toDateString(),
                'after_or_equal:'.$today->subYear()->toDateString(),
            ],
            'result' => ['sometimes', 'required', Rule::enum(MedicalResult::class)],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:1000'],
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
            'medical_date.before_or_equal' => 'The medical date cannot be in the future.',
            'medical_date.after_or_equal' => 'The medical date cannot be more than 1 year ago.',
            'result.enum' => 'The result must be fit or unfit.',
        ];
    }
}
