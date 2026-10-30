<?php

namespace App\Models;

use App\Enums\TransferDirection;
use App\Models\Concerns\HasApproval;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cash in hand deposited to a bank, or withdrawn from a bank into cash.
 * Write through App\Services\CashTransferService.
 */
#[Fillable(['date', 'direction', 'amount', 'bank_name', 'bank_branch', 'note'])]
class CashTransfer extends Model
{
    use HasApproval;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'year' => 'integer',
            'sequence' => 'integer',
            'direction' => TransferDirection::class,
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
