<?php

namespace App\Http\Requests\Agents;

/**
 * Same rules as adding, all optional (PATCH), plus is_active. is_own is set
 * only when adding: an agent never becomes or stops being the Own agent.
 */
class UpdateRecruitmentAgentRequest extends StoreRecruitmentAgentRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...array_map(fn (array $rules) => ['sometimes', ...$rules], collect(parent::rules())->except('is_own')->all()),
            // A partner agent still holding passports in process (flight not done) cannot be
            // deactivated. The Own agent can.
            'is_active' => ['sometimes', 'boolean', function (string $attribute, mixed $value, \Closure $fail) {
                $agent = $this->agent();
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN) || ! $agent || $agent->is_own || ! $agent->is_active) {
                    return;
                }
                $count = $agent->passportsStillWithAgent();
                if ($count > 0) {
                    $fail("This agent still has {$count} passport(s) in process (flight not done). Finish them or move them to another agent before deactivating.");
                }
            }],
            'is_own' => ['prohibited'],
        ];
    }
}
