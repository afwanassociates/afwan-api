<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MedicalCenter;
use App\Models\User;

/**
 * The passport team (data entry and the operations head) and admins search
 * active centres for the medical form. The passport team adds one from the
 * medical slip; only the operations head manages the list (rename,
 * deactivate, delete an unused one).
 */
class MedicalCenterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::OperationHead, UserRole::Admin, UserRole::SuperAdmin);
    }

    /**
     * See the full list, inactive centres included.
     */
    public function manage(User $user): bool
    {
        return $user->hasRole(UserRole::OperationHead);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::OperationHead);
    }

    public function update(User $user, MedicalCenter $center): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, MedicalCenter $center): bool
    {
        return $this->manage($user);
    }
}
