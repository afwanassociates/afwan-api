<?php

namespace App\Http\Requests\Agents;

/**
 * Assign one agent and date to many passports (first assignments only).
 */
class BulkAssignAgentRequest extends AssignAgentRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'passport_ids' => ['required', 'array', 'min:1', 'max:500'],
            'passport_ids.*' => ['integer', 'distinct'],
        ];
    }
}
