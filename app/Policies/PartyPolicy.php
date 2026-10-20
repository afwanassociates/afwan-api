<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Party;
use App\Models\User;

/**
 * accounts, admin and super_admin keep parties and their ledgers; only admin
 * and super_admin delete a party (and only one without transactions).
 */
class PartyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(...UserRole::accountsTeam());
    }

    public function view(User $user, Party $party): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Party $party): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Party $party): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin);
    }
}
