<?php

namespace App\Http\Requests\Agents;

use App\Http\Requests\Accounts\StorePartyRequest;
use App\Models\RecruitmentAgent;
use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add or edit a recruitment agent. The RL number is required and unique (stored
 * upper-cased); the phone, address and notes are optional.
 */
class StoreRecruitmentAgentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name', 'phone', 'address', 'notes'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Normalize::name($this->input($field)) ?: null]);
            }
        }
        if (is_string($this->input('rl_number'))) {
            $this->merge(['rl_number' => mb_strtoupper(Normalize::name($this->input('rl_number')) ?? '') ?: null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'rl_number' => ['required', 'string', 'max:50', Rule::unique('recruitment_agents', 'rl_number')->ignore($this->agent())],
            'phone' => ['nullable', 'string', 'max:20', 'regex:'.StorePartyRequest::MOBILE_PATTERN],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Our own company as an agent: only when adding, and only one.
            'is_own' => ['sometimes', 'boolean', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value && RecruitmentAgent::query()->where('is_own', true)->exists()) {
                    $fail('The Own agent (our company) already exists.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the agent\'s name.',
            'rl_number.required' => 'Enter the RL number.',
            'rl_number.unique' => 'Another agent already has this RL number.',
            'phone.regex' => 'Enter a valid phone number (digits, spaces or dashes, optional +).',
        ];
    }

    public function agent(): ?RecruitmentAgent
    {
        return $this->route('recruitment_agent');
    }
}
