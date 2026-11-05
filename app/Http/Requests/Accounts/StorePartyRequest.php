<?php

namespace App\Http\Requests\Accounts;

use App\Enums\BalanceType;
use App\Enums\OwnerDesignation;
use App\Enums\PartyKind;
use App\Enums\PartyType;
use App\Http\Requests\Accounts\Concerns\ValidatesClientParty;
use App\Models\Party;
use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a party or an owner. The party ID is assigned by the server.
 */
class StorePartyRequest extends FormRequest
{
    use ValidatesClientParty;

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
        foreach (['name', 'agency_owner_name', 'mobile', 'address', 'tin_bin'] as $field) {
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

        foreach (['tin_bin', 'rl_number', 'agency_owner_name', 'address', 'nid_passport', 'notes'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /**
     * A client is an agency or a person (kind). An agency has an RL number; a
     * person does not. Data entry quick-adds clients with only a name and a
     * mobile, so a client's address, NID / passport and agency owner name are
     * optional (the accounts team fills them in later). Owners need them all.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->party() ? 'sometimes' : 'required';
        $isOwner = $this->partyType() === PartyType::Owner;
        $ownerOnly = $isOwner ? [$required] : ['sometimes', 'nullable'];

        return [
            'type' => $this->party()
                ? ['prohibited']
                : ['required', Rule::in(PartyType::values())],
            'kind' => $isOwner
                ? ['prohibited']
                : [$required, Rule::enum(PartyKind::class)],
            'rl_number' => $this->rlNumberRules(),
            'name' => [$required, 'string', 'max:150', ...($isOwner ? [] : $this->clientNameRules())],
            // An agency's owner (their NID / passport is nid_passport).
            'agency_owner_name' => $isOwner || $this->clientKind() === PartyKind::Person
                ? ['prohibited']
                : ['sometimes', 'nullable', 'string', 'max:150'],
            'designation' => $isOwner
                ? [$required, Rule::in(OwnerDesignation::values())]
                : ['prohibited'],
            'mobile' => [$required, 'string', 'max:30', 'regex:'.self::MOBILE_PATTERN],
            'address' => [...$ownerOnly, 'string', 'max:500'],
            'nid_passport' => [...$ownerOnly, 'string', 'max:50', Rule::unique('parties', 'nid_passport')->ignore($this->party())],
            'tin_bin' => ['sometimes', 'nullable', 'string', 'max:50'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
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
        $isOwner = $this->partyType() === PartyType::Owner;
        $isPerson = $this->clientKind() === PartyKind::Person;

        return [
            ...$this->clientMessages(),
            'type.required' => 'Choose whether this is a party or an owner.',
            'type.prohibited' => 'The type of a party cannot be changed.',
            'kind.required' => 'Choose whether the party is an agency or a person.',
            'kind.prohibited' => 'Owners are not an agency or a person.',
            'name.required' => $isOwner || $isPerson ? 'Enter the name.' : 'Enter the agency name.',
            'agency_owner_name.prohibited' => $isOwner ? 'Company owners have no agency owner.' : 'Only agencies have an agency owner.',
            'designation.required' => 'Choose the designation (Chairman, Managing Director or Director).',
            'designation.in' => 'Choose Chairman, Managing Director or Director.',
            'designation.prohibited' => 'Only owners have a designation.',
            'mobile.required' => $isOwner || $isPerson ? 'Enter the mobile number.' : "Enter the agency's mobile number.",
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

    protected function kindField(): string
    {
        return 'kind';
    }

    /**
     * The party being edited, or null when creating.
     */
    public function party(): ?Party
    {
        return $this->route('party');
    }
}
