<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

/**
 * data_entry, admin and super_admin search and create company records;
 * only admin and super_admin edit, deactivate or delete them. accounts may
 * also read the company report.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::Admin, UserRole::SuperAdmin);
    }

    /**
     * Read the company-wise report (accounts included, read only).
     */
    public function viewReport(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::Accounts, UserRole::AccountsHead, UserRole::Admin, UserRole::SuperAdmin);
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

    public function update(User $user, Company $company): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Company $company): bool
    {
        return $this->manage($user);
    }
}
