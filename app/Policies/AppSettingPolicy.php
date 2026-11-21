<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * data_entry, admin and super_admin read the defaults; only admin and
 * super_admin change settings.
 */
class AppSettingPolicy
{
    public function view(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::OperationHead, UserRole::Admin, UserRole::SuperAdmin);
    }

    public function update(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin);
    }
}
