<?php

namespace App\Http\Requests\Accounts;

use App\Enums\BalanceType;
use App\Enums\PartyType;
use App\Models\Party;
use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a party or an owner. The party ID is assigned by the server.
 */
class StorePartyRequest extends FormRequest
{
    /** Digits, spaces and dashes, with an optional leading +. */
    public const MOBILE_PATTERN = '/^\+?[0-9][0-9 \-]{5,28}$/';

    /** Largest amount a decimal(15,2) column holds. */
    public const MAX_AMOUNT = 9999999999999.99;

    /**
     * Normalize the name, the RL number (upper case) and the NID/passport (no
     * spaces, upper case) before the uniqueness checks, so they see the stored value.
     */
    protected function prepareForValidation(): void
    {
        foreach (['name', 'mobile', 'address', 'tin_bin'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Normalize::name($this->input($field))]);
            }
        }

        if (is_string($this->input('nid_passport'))) {
            $this->merge(['nid_passport' => Normalize::passportNumber($this->input('nid_passport'))]);
        }

        if (is_string($this->input('rl_number'))) {
            $this->merge(['rl_number' => Normalize::passportName($this->input('rl_number'))]);
        }

        foreach (['tin_bin', 'rl_number'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->party() ? 'sometimes' : 'required';

        return [
            'type' => $this->party()
                ? ['prohibited']
                : ['required', Rule::in(PartyType::values())],
            'rl_number' => $this->partyType() === PartyType::Owner
                ? ['prohibited']
                : [$required, 'string', 'max:50', Rule::unique('parties', 'rl_number')->ignore($this->party())],
            'name' => [$required, 'string', 'max:150'],
            'mobile' => [$required, 'string', 'max:30', 'regex:'.self::MOBILE_PATTERN],
            'address' => [$required, 'string', 'max:500'],
            'nid_passport' => [$required, 'string', 'max:50', Rule::unique('parties', 'nid_passport')->ignore($this->party())],
            'tin_bin' => ['sometimes', 'nullable', 'string', 'max:50'],
            'opening_balance' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:'.self::MAX_AMOUNT],
            'opening_balance_type' => ['sometimes', Rule::in(BalanceType::values())],
            'is_active' => $this->party() ? ['sometimes', 'boolean'] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Choose whether this is a party or an owner.',
            'type.prohibited' => 'The type of a party cannot be changed.',
            'rl_number.required' => 'Enter the RL number.',
            'rl_number.prohibited' => 'Owners do not have an RL number.',
            'rl_number.unique' => 'A party with this RL number already exists.',
            'name.required' => 'Enter the name.',
            'mobile.required' => 'Enter the mobile number.',
            'mobile.regex' => 'Enter a valid mobile number (digits, spaces or dashes, optional +).',
            'address.required' => 'Enter the address.',
            'nid_passport.required' => 'Enter the NID or passport number.',
            'nid_passport.unique' => 'A party with this NID/passport number already exists.',
            'opening_balance.min' => 'The opening balance cannot be negative; choose Payable instead.',
            'opening_balance.decimal' => 'The opening balance can have at most 2 decimal places.',
        ];
    }

    /**
     * The type of the party: fixed when editing, as sent when creating.
     */
    private function partyType(): ?PartyType
    {
        return $this->party()?->type ?? PartyType::tryFrom((string) $this->input('type'));
    }

    /**
     * The party being edited, or null when creating.
     */
    public function party(): ?Party
    {
        return $this->route('party');
    }
}
