<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * The accounts team reads the cash opening balance and the cash book; only
 * the accounts head sets the opening balance.
 */
class CashSettingPolicy
{
    public function view(User $user): bool
    {
        return $user->hasRole(...UserRole::accountsTeam());
    }

    public function update(User $user): bool
    {
        return $user->hasRole(...UserRole::accountsManagers());
    }
}
