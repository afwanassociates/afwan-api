<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\MedicalResult;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only the result is accepted: the date, centre and slip come from the
 * passport, and valid_until and the tracking columns are derived in
 * App\Services\MedicalService.
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
        return [
            'result' => ['required', Rule::enum(MedicalResult::class)],
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
        ];
    }
}
