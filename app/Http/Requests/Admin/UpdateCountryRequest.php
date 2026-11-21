<?php

namespace App\Http\Requests\Admin;

use App\Models\Country;
use App\Support\Normalize;
use App\Support\Settings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCountryRequest extends FormRequest
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
     * Get the validation rules that apply to the request. The code cannot change.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('countries', 'name')->ignore($this->country()->code, 'code')],
            'is_active' => ['sometimes', 'boolean'],
            'is_pinned' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
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
            'name.unique' => 'A country with this name already exists.',
        ];
    }

    /**
     * A country used as a default cannot be deactivated.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->has('is_active') || $this->boolean('is_active')) {
                    return;
                }

                $setting = app(Settings::class)->defaultCountryCodes()[$this->country()->code] ?? null;

                if ($setting !== null) {
                    $which = $setting === Settings::DEFAULT_COMPANY_COUNTRY ? 'company' : 'passport';

                    $validator->errors()->add('is_active', "{$this->country()->name} is the default {$which} country and cannot be deactivated. Choose another default first.");
                }
            },
        ];
    }

    /**
     * The country being edited.
     */
    public function country(): Country
    {
        return $this->route('country');
    }
}
