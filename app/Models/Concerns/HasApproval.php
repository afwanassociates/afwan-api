<?php

namespace App\Models\Concerns;

use App\Enums\ApprovalStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accounts entry that needs approval: status, who prepared, last edited,
 * approved or returned it. Approve and return through App\Services\ApprovalService.
 *
 * @property ApprovalStatus $status
 */
trait HasApproval
{
    public function initializeHasApproval(): void
    {
        $this->mergeCasts([
            'status' => ApprovalStatus::class,
            'approved_at' => 'datetime',
            'returned_at' => 'datetime',
        ]);
    }

    public function isApproved(): bool
    {
        return $this->status === ApprovalStatus::Approved;
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    /**
     * Whether the user prepared or last edited this entry (and so may not approve it).
     */
    public function isPreparedBy(User $user): bool
    {
        return $this->created_by === $user->id || $this->updated_by === $user->id;
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function approved(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), ApprovalStatus::Approved->value);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function returner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }
}
