<?php

namespace App\Http\Requests\DataEntry;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Quick Add a company from the Add Passport page: the name (unique), the
 * quota (at least 1) and an active country are required; the other fields
 * stay optional. The admin Companies CRUD keeps its own, optional quota.
 */
class QuickAddCompanyRequest extends StoreCompanyRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'quota' => ['required', 'integer', 'min:1', 'max:4294967295'],
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
            ...parent::messages(),
            'name.required' => 'Enter the company name.',
            'quota.required' => 'Enter the quota (the number of workers the company needs).',
            'quota.integer' => 'The quota must be a whole number.',
            'quota.min' => 'The quota must be at least 1.',
            'country_code.required' => 'Choose the company country.',
        ];
    }
}
