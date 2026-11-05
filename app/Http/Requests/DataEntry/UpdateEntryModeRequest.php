<?php

namespace App\Http\Requests\DataEntry;

use App\Enums\EntryMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mark a fit passport Online (null clears the mark). The medical
 * and Calling rules are checked in App\Services\WorkflowService.
 */
class UpdateEntryModeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'entry_mode' => ['present', 'nullable', Rule::enum(EntryMode::class)],
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
            'entry_mode.present' => 'Send entry_mode "online" to mark the passport, or null to clear the mark.',
            'entry_mode.enum' => 'The only mark is Online: send entry_mode "online", or null to clear it.',
        ];
    }
}
