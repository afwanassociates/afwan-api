<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\PartyKind;
use App\Http\Requests\Accounts\Concerns\ValidatesClientParty;
use App\Http\Requests\Accounts\StorePartyRequest;
use App\Models\Party;
use App\Support\Normalize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A reference is a client party (agency or person) as the passport team sees
 * it: the short form of Quick Add and the references page. `type` is the
 * party's kind and `phone` its mobile. The accounts team fills in the rest
 * (address, NID / passport, agency owner) on the party form.
 */
class StoreReferenceRequest extends FormRequest
{
    use ValidatesClientParty;

    /**
     * Normalize the name, phone and RL number before the uniqueness checks.
     * The passport form's Quick Add may send no type: a new reference without
     * one is a person.
     */
    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Normalize::name($this->input($field))]);
            }
        }

        if (is_string($this->input('rl_number'))) {
            $this->merge(['rl_number' => Normalize::passportName($this->input('rl_number'))]);
        }

        foreach (['rl_number', 'notes'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }

        if ($this->party() === null && ! $this->filled('type')) {
            $this->merge(['type' => PartyKind::Person->value]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string|mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PartyKind::class)],
            'name' => ['required', 'string', 'max:150', ...$this->clientNameRules()],
            'phone' => ['required', 'string', 'max:30', 'regex:'.StorePartyRequest::MOBILE_PATTERN],
            'rl_number' => $this->rlNumberRules(),
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
            ...$this->clientMessages(),
            'phone.required' => 'Enter the phone number.',
            'phone.regex' => 'Enter a valid phone number (digits, spaces or dashes, optional +).',
        ];
    }

    /**
     * The validated fields as party columns: type is the kind, phone the mobile.
     *
     * @return array<string, mixed>
     */
    public function partyAttributes(): array
    {
        $columns = ['type' => 'kind', 'name' => 'name', 'phone' => 'mobile', 'rl_number' => 'rl_number', 'notes' => 'notes'];
        $attributes = [];

        foreach ($this->safe()->only(array_keys($columns)) as $field => $value) {
            $attributes[$columns[$field]] = $value;
        }

        return $attributes;
    }

    protected function kindField(): string
    {
        return 'type';
    }

    /**
     * The reference (client party) being edited, or null when creating.
     */
    public function party(): ?Party
    {
        return $this->route('reference');
    }
}
