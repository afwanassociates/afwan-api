<?php

namespace App\Http\Requests\DataEntry;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Same rules as creating, all optional (PATCH), plus is_active.
 */
class UpdateReferenceRequest extends StoreReferenceRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            ...array_map(fn (array $rules) => ['sometimes', ...$rules], parent::rules()),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
