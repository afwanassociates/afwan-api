<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\MedicalResult;
use App\Support\LocalDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The result, and for fit its issue and expiry dates (the expiry becomes
 * valid_until; nothing is computed). The medical date, centre and slip come
 * from the passport; the tracking columns are set in App\Services\MedicalService.
 */
class StoreMedicalResultRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $today = LocalDate::today()->toDateString();

        return [
            'result' => ['required', Rule::enum(MedicalResult::class)],
            // A fit result's validity, as printed on it; unfit needs no dates.
            'issue_date' => ['exclude_unless:result,fit', 'required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'expiry_date' => ['exclude_unless:result,fit', 'required', 'date_format:Y-m-d', 'after:issue_date'],
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
            'result.enum' => 'The result must be fit or unfit.',
            'issue_date.required' => 'Enter the issue date of the fit medical.',
            'issue_date.date_format' => 'The issue date must be a date (YYYY-MM-DD).',
            'issue_date.before_or_equal' => 'The issue date cannot be in the future.',
            'expiry_date.required' => 'Enter the expiry date of the fit medical.',
            'expiry_date.date_format' => 'The expiry date must be a date (YYYY-MM-DD).',
            'expiry_date.after' => 'The expiry date must be after the issue date.',
        ];
    }
}
