<?php

namespace App\Http\Requests\Accounts\Concerns;

use App\Enums\PartyKind;
use App\Enums\PartyType;
use App\Models\Party;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The rules a client party's kind brings, shared by the accounts party form
 * (StorePartyRequest) and the passport team's reference forms
 * (StoreReferenceRequest): an agency has a unique RL number, a person has
 * none. A client's name is unique within its kind, checked when it is created
 * or renamed (old duplicates from the reference merge stay editable).
 */
trait ValidatesClientParty
{
    /**
     * The party being edited, or null when creating.
     */
    abstract public function party(): ?Party;

    /**
     * The kind as sent, else the edited party's.
     */
    protected function clientKind(): ?PartyKind
    {
        return PartyKind::tryFrom((string) $this->input($this->kindField())) ?? $this->party()?->kind;
    }

    /**
     * Agency: required on create, and on edit when the kind or the RL number
     * is sent (so an edit cannot leave an agency without one). Person: none.
     *
     * @return list<mixed>
     */
    protected function rlNumberRules(): array
    {
        if ($this->clientKind() !== PartyKind::Agency) {
            return ['prohibited'];
        }

        $required = $this->party() === null || $this->has($this->kindField()) || $this->has('rl_number') ? 'required' : 'sometimes';

        return [$required, 'string', 'max:50', Rule::unique('parties', 'rl_number')->ignore($this->party())];
    }

    /**
     * Unique among clients of the same kind when creating or renaming.
     *
     * @return list<mixed>
     */
    protected function clientNameRules(): array
    {
        $party = $this->party();
        $name = $this->input('name');
        $renamed = $party === null || (is_string($name) && Str::lower($name) !== Str::lower((string) $party->name));
        $kind = $this->clientKind();

        if (! $renamed || $kind === null) {
            return [];
        }

        return [Rule::unique('parties', 'name')
            ->where('type', PartyType::Client->value)
            ->where('kind', $kind->value)
            ->ignore($party)];
    }

    /**
     * @return array<string, string>
     */
    protected function clientMessages(): array
    {
        return [
            'rl_number.required' => 'Enter the RL number of the agency.',
            'rl_number.prohibited' => $this->input('type') === PartyType::Owner->value || $this->party()?->type === PartyType::Owner
                ? 'Owners do not have an RL number.'
                : 'Only agencies have an RL number.',
            'rl_number.unique' => 'A party with this RL number already exists.',
            'name.unique' => $this->clientKind() === PartyKind::Person
                ? 'A person with this name already exists.'
                : 'An agency with this name already exists.',
        ];
    }

    /**
     * The input field that holds the kind (kind or type).
     */
    abstract protected function kindField(): string;
}
