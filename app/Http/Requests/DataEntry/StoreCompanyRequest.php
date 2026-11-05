<?php

namespace App\Http\Requests\DataEntry;

use App\Models\Company;
use App\Rules\ActiveCountry;
use App\Support\Normalize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    /**
     * Normalize the name before the uniqueness check, and the country code.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Normalize::name($this->input('name'))]);
        }

        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => Normalize::countryCode($this->input('country_code'))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     * Case-insensitive through the column collation; soft-deleted rows count too.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150', Rule::unique('companies', 'name')->ignore($this->company())],
            // Where the employer is. When editing, the current country stays valid even if deactivated.
            'country_code' => ['required', 'string', 'size:2', new ActiveCountry($this->company()?->country_code)],
            // Workers the company asked for; the company report shows the balance.
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
            'name.required' => 'Enter the company name.',
            'name.unique' => 'A company with this name already exists.',
            'country_code.required' => 'Choose the company country.',
            'quota.required' => 'Enter the quota (the number of workers the company needs).',
            'quota.integer' => 'The quota must be a whole number.',
            'quota.min' => 'The quota must be at least 1.',
        ];
    }

    /**
     * The company being edited, or null when creating.
     */
    public function company(): ?Company
    {
        return $this->route('company');
    }
}
