<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\MedicalCenter;
use App\Models\User;

/**
 * data_entry, admin and super_admin search active centres for the medical
 * form; only admin and super_admin manage the list.
 */
class MedicalCenterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::Admin, UserRole::SuperAdmin);
    }

    /**
     * See the full list, inactive centres included.
     */
    public function manage(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
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
