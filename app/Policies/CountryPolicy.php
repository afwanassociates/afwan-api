<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Country;
use App\Models\User;

/**
 * data_entry, admin and super_admin read active countries for dropdowns;
 * only admin and super_admin manage the list.
 */
class CountryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::Admin, UserRole::SuperAdmin);
    }

    /**
     * See the full list, inactive countries and usage counts included.
     */
    public function manage(User $user): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Country $country): bool
    {
        return $this->manage($user);
    }
}
