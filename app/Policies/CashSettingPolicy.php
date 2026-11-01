<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * The accounts team reads the cash settings and the cash book. Nobody sets an
 * opening balance: each day's opening is the previous day's closing.
 */
class CashSettingPolicy
{
    public function view(User $user): bool
    {
        return $user->hasRole(...UserRole::accountsTeam());
    }
}
