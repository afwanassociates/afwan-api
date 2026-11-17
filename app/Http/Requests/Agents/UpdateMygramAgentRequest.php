<?php

namespace App\Http\Requests\Agents;

/**
 * Same rules as adding, all optional (PATCH), plus is_active.
 */
class UpdateMygramAgentRequest extends StoreMygramAgentRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...array_map(fn (array $rules) => ['sometimes', ...$rules], parent::rules()),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
