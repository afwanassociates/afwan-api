<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\StepStatus;
use App\Support\LocalDate;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Calling / Work Permit in one update: the calling receive date, issued date
 * and expiry date, all required. Saving them makes the step Permitted; the
 * record's date is the receive date.
 */
class UpdateCallingRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        // "Today" is the Dhaka calendar date, not UTC's.
        $today = LocalDate::today()->toDateString();

        return [
            'receive_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'issue_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after:issue_date'],
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
            'receive_date.required' => 'Enter the calling receive date.',
            'issue_date.required' => 'Enter the issued date.',
            'valid_until.required' => 'Enter the expiry date.',
            'receive_date.before_or_equal' => 'The calling receive date cannot be in the future.',
            'issue_date.before_or_equal' => 'The issued date cannot be in the future.',
            'valid_until.after' => 'The expiry date must be after the issued date.',
        ];
    }

    /**
     * The values for the completed calling record.
     *
     * @return array<string, mixed>
     */
    public function recordData(): array
    {
        return [
            'status' => StepStatus::Completed,
            'step_date' => $this->validated('receive_date'),
            'details' => ['receive_date' => $this->validated('receive_date'), 'issue_date' => $this->validated('issue_date')],
            'valid_until' => $this->validated('valid_until'),
        ];
    }
}
