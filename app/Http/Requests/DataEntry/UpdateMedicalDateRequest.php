<?php

namespace App\Http\Requests\DataEntry;

use App\Support\LocalDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The date the passport goes (or went) for its medical. The slip-date and
 * result-lock rules are checked in App\Services\MedicalService.
 */
class UpdateMedicalDateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        // "Today" is the Dhaka calendar date, not UTC's.
        return [
            'medical_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.LocalDate::today()->toDateString()],
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
        ];
    }
}
