<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\DataEntry\StoreCompanyRequest as QuickAddCompanyRequest;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An admin creates a company with every field, and may create it inactive.
 */
class StoreCompanyRequest extends QuickAddCompanyRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
