<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Reference;
use App\Models\User;

/**
 * data_entry, admin and super_admin search and create reference records;
 * only admin and super_admin edit, deactivate or delete them.
 */
class ReferencePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::Admin, UserRole::SuperAdmin);
    }

    /**
     * Search references in the reports' filters (accounts included, read only).
     */
    public function viewReport(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::Accounts, UserRole::Admin, UserRole::SuperAdmin);
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
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin);
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
