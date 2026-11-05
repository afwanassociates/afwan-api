<?php

namespace App\Http\Requests\DataEntry;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Same rules as creating, all optional (PATCH), plus is_active.
 */
class UpdateReferenceRequest extends StoreReferenceRequest
{
    /**
     * Every field is optional when editing (a list toggle sends is_active
     * only); a sent field follows the create rules. The RL number decides
     * itself when it is needed (ValidatesClientParty::rlNumberRules).
     *
     * @return array<string, array<int, ValidationRule|string|mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        return [
            ...array_map(fn (array $rules) => ['sometimes', ...$rules], array_diff_key($rules, ['rl_number' => true])),
            'rl_number' => $rules['rl_number'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
