<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Party;
use App\Models\User;

/**
 * The accounts team (accounts and the accounts head) keeps parties and their
 * ledgers; only the accounts head deletes a party (and only one without
 * transactions). Admins have no parties.
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
        return $user->hasRole(...UserRole::accountsManagers());
    }
}
