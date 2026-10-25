<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use App\Support\Workflow;

/**
 * The passport team records steps; data entry edits its own records until a
 * later step has records. Only the operations head edits any record, deletes
 * and undoes (any step: the Online mark, BMET actions, the flight booking and
 * confirmation). The step order itself is enforced by App\Services\WorkflowService.
 */
class PassportStepRecordPolicy
{
    public function __construct(protected Workflow $workflow) {}

    public function viewAny(User $user): bool
    {
        return $user->hasRole(...UserRole::passportTeam());
    }

    /**
     * Read the step work lists (accounts too, read only).
     */
    public function viewList(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::OperationHead, UserRole::Accounts, UserRole::AccountsHead, UserRole::Admin, UserRole::SuperAdmin);
    }

    public function create(User $user, PassportEntry $passport): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Load passportEntry to avoid a query.
     */
    public function update(User $user, PassportStepRecord $record): bool
    {
        return $user->hasRole(...UserRole::passportManagers())
            || ($user->hasRole(UserRole::DataEntry)
                && $record->created_by === $user->id
                && $record->passportEntry !== null
                && ! $this->workflow->hasRecordsAfter($record->passportEntry, $record->step_key));
    }

    public function delete(User $user, PassportStepRecord $record): bool
    {
        return $user->hasRole(...UserRole::passportManagers());
    }

    /**
     * Undo a step: clear the Online mark, a BMET action, the flight booking or
     * the flight confirmation (the operations head only).
     */
    public function undo(User $user): bool
    {
        return $user->hasRole(...UserRole::passportManagers());
    }
}
