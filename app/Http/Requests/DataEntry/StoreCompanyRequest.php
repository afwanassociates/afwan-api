<?php

namespace App\Http\Requests\DataEntry;

use App\Models\Company;
use App\Support\Normalize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    /**
     * Normalize the name before the uniqueness check.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Normalize::name($this->input('name'))]);
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
