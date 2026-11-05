<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The company's cash-in-hand opening balance (one row, id 1). Change it
 * through App\Services\CashBook, which also locks this row around every
 * cash write.
 */
class CashSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'opening_date' => 'date:Y-m-d',
        ];
    }

    /**
     * The settings row, locked for the rest of the transaction when $lock is true.
     */
    public static function current(bool $lock = false): self
    {
        $query = static::query()->whereKey(1);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? static::query()->forceCreate(['id' => 1, 'opening_balance' => 0]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
