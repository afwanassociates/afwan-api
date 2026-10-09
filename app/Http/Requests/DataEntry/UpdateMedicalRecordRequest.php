<?php

namespace App\Http\Requests\DataEntry;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Same rules as recording, but every field is optional (PATCH).
 */
class UpdateMedicalRecordRequest extends StoreMedicalRecordRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return array_map(fn (array $rules) => ['sometimes', ...$rules], parent::rules());
    }
}
