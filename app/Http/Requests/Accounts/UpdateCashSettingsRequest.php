<?php

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The company's cash-in-hand opening balance and the date it applies from.
 */
class UpdateCashSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'opening_balance' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:'.StorePartyRequest::MAX_AMOUNT],
            'opening_date' => ['required', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'opening_balance.required' => 'Enter the opening cash in hand (0 if none).',
            'opening_balance.min' => 'The opening cash cannot be negative.',
            'opening_date.required' => 'Choose the date the opening balance applies from.',
        ];
    }
}
