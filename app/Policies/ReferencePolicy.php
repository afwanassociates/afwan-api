<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Reference;
use App\Models\User;

/**
 * The passport team searches and creates reference records; only the
 * operations head edits, deactivates or deletes them.
 */
class ReferencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(...UserRole::passportTeam());
    }

    /**
     * Search references in the reports' filters (accounts included, read only).
     */
    public function viewReport(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::OperationHead, UserRole::Accounts, UserRole::AccountsHead, UserRole::Admin, UserRole::SuperAdmin);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Manage the full list, including inactive records.
     */
    public function manage(User $user): bool
    {
        return $user->hasRole(...UserRole::passportManagers());
    }

    public function update(User $user, Reference $reference): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Reference $reference): bool
    {
        return $this->manage($user);
    }
}
