<?php

namespace App\Rules;

use App\Models\Country;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;

/**
 * The value is the code of an active country. When editing, the record's
 * current country is also accepted even if it has been deactivated since.
 */
class ActiveCountry implements ValidationRule
{
    public function __construct(protected ?string $current = null) {}

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = is_string($value) && Country::query()
            ->whereKey($value)
            ->where(fn (Builder $query) => $query
                ->where('is_active', true)
                ->when($this->current, fn (Builder $query) => $query->orWhere('code', $this->current)))
            ->exists();

        if (! $exists) {
            $fail('The selected country does not exist or is inactive.');
        }
    }
}
