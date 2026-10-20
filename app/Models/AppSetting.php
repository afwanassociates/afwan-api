<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admin-managed setting. Read and write through App\Support\Settings,
 * which caches them. updated_by is set in code.
 */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
