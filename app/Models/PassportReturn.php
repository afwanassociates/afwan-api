<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passport handed back to its owner. Write only through
 * App\Services\PassportReturnService; a return is never deleted, an undo marks
 * it undone (undone_at, undone_by) so it stays in the history.
 */
#[Fillable(['return_date', 'receiver_name', 'receiver_phone', 'receiver_id_no', 'remarks'])]
class PassportReturn extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'return_date' => 'date',
            'undone_at' => 'datetime',
        ];
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }

    /**
     * Includes soft-deleted passports, so the history stays complete.
     *
     * @return BelongsTo<PassportEntry, $this>
     */
    public function passportEntry(): BelongsTo
    {
        return $this->belongsTo(PassportEntry::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function undoer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }
}
