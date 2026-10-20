<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Everyone in accounts reads the cash opening balance and the cash book;
 * only admin and super_admin set the opening balance.
 */
class CashSettingPolicy
{
    public function view(User $user): bool
    {
        return $user->hasRole(...UserRole::accountsTeam());
    }

    public function update(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin);
    }
}
