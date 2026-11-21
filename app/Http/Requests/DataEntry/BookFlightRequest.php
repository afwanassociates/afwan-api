<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\StepStatus;
use App\Models\PassportEntry;
use App\Support\Workflow;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Save the flight booking date: required, today or later is fine, but not
 * before the visa issue date.
 */
class BookFlightRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'booking_date' => ['required', 'date_format:Y-m-d'],
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
            'booking_date.required' => 'Enter the flight booking date.',
            'booking_date.date_format' => 'The flight booking date must be a date (YYYY-MM-DD).',
        ];
    }

    /**
     * The booking cannot be before the visa issue date.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('booking_date')) {
                    return;
                }

                /** @var PassportEntry $passport */
                $passport = $this->route('passport');
                $workflow = app(Workflow::class);
                $visa = $workflow->latestRecords($passport->load('stepRecords'))->get('visa');

                if ($visa?->status !== StepStatus::Completed) {
                    return;
                }

                $issued = $visa->detail('issue_date') ?? $workflow->completedOn($visa);

                if ($this->input('booking_date') < $issued) {
                    $validator->errors()->add('booking_date', 'The flight booking date cannot be before the visa issue date ('.date('d-m-Y', strtotime($issued)).').');
                }
            },
        ];
    }
}
