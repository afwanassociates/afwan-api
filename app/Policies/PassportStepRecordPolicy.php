<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use App\Support\Workflow;

/**
 * data_entry records steps and edits its own records until a later step has
 * records. admin and super_admin edit any record and are the only ones who
 * delete. The step order itself is enforced by App\Services\WorkflowService.
 */
class PassportStepRecordPolicy
{
    public function __construct(protected Workflow $workflow) {}

    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::Admin, UserRole::SuperAdmin);
    }

    /**
     * Read the step work lists (accounts too, read only).
     */
    public function viewList(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::Accounts, UserRole::Admin, UserRole::SuperAdmin);
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
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin)
            || ($user->hasRole(UserRole::DataEntry)
                && $record->created_by === $user->id
                && $record->passportEntry !== null
                && ! $this->workflow->hasRecordsAfter($record->passportEntry, $record->step_key));
    }

    public function delete(User $user, PassportStepRecord $record): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin);
    }
}
