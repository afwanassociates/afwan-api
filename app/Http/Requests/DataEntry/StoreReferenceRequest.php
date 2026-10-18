<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\ReferenceType;
use App\Models\Reference;
use App\Support\Normalize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReferenceRequest extends FormRequest
{
    /**
     * Normalize the name before the uniqueness check. The passport form has a single
     * Reference field without a person/agency choice, so a new reference without a
     * type is stored as a person.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Normalize::name($this->input('name'))]);
        }

        if ($this->reference() === null && ! $this->filled('type')) {
            $this->merge(['type' => ReferenceType::Person->value]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     * The name is unique within its type; the column collation makes the
     * comparison case-insensitive. Soft-deleted rows count too.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        $type = $this->input('type', $this->reference()?->type->value);

        return [
            'type' => ['required', Rule::enum(ReferenceType::class)],
            'name' => [
                'required', 'string', 'max:150',
                Rule::unique('references', 'name')->where('type', is_string($type) ? $type : '')->ignore($this->reference()),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:2000'],
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
            'name.unique' => 'A reference with this name already exists.',
        ];
    }

    /**
     * The reference being edited, or null when creating.
     */
    public function reference(): ?Reference
    {
        return $this->route('reference');
    }
}
