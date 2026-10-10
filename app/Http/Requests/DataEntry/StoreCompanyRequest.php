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
     * Normalize the name before the uniqueness check, the country code and
     * the agent and agency names.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Normalize::name($this->input('name'))]);
        }

        if (is_string($this->input('country_code'))) {
            $this->merge(['country_code' => Normalize::countryCode($this->input('country_code'))]);
        }

        foreach (['agent_name', 'bd_agency_name'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Normalize::name($this->input($field))]);
            }
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
            // The company's agent and the Bangladeshi recruiting agency (all optional).
            'agent_name' => ['nullable', 'string', 'max:150'],
            'agent_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'agent_email' => ['nullable', 'string', 'email', 'max:150'],
            'bd_agency_name' => ['nullable', 'string', 'max:150'],
            // Workers the company asked for; the company report shows the balance.
            'quota' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
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
            'name.unique' => 'A company with this name already exists.',
            'agent_phone.regex' => 'The agent phone may only contain digits, spaces, +, - and brackets.',
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
