<?php

namespace App\Http\Requests\DataEntry;

use App\Rules\PassportScanFile;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Same rules as creating, but every field is optional (PATCH): a field is
 * only required to be filled in when it is sent, as the full edit form does.
 * Old entries missing a date of birth, expiry, country or reference stay
 * valid until someone saves the form for them.
 */
class UpdatePassportEntryRequest extends StorePassportEntryRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            ...array_map(fn (array $rules) => ['sometimes', ...$rules], parent::rules()),
            // Required until the passport has a scan; then an optional replacement.
            'scan' => PassportScanFile::rules(required: ! $this->entry()?->hasScan()),
        ];
    }
}
