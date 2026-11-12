<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reference's own price per passport for one company (instead of the
 * company's standard rate). Set by the accounts head (App\Support\PassportPricing).
 */
#[Fillable(['party_id', 'company_id', 'rate'])]
class PartyCompanyRate extends Model
{
    protected function casts(): array
    {
        return ['rate' => 'decimal:2'];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }
}
