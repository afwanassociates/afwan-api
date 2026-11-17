<?php

namespace App\Http\Requests\Agents;

use App\Support\Normalize;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Assign (or change) a passport's MYGRAM agent. The service checks the rest:
 * an active agent, not in the future, a reason for a change.
 */
class AssignAgentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('remark'))) {
            $this->merge(['remark' => Normalize::name($this->input('remark')) ?: null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mygram_agent_id' => ['required', 'integer'],
            'assigned_date' => ['required', 'date_format:Y-m-d'],
            'remark' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mygram_agent_id.required' => 'Choose the MYGRAM agent.',
            'assigned_date.required' => 'Enter the date the passport went to the agent.',
            'assigned_date.date_format' => 'Enter a valid date.',
        ];
    }
}
