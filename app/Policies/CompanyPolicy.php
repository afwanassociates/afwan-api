<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

/**
 * The passport team searches and creates company records; only the operations
 * head manages them (the full list, edit, deactivate, delete). Everyone else
 * with reports reads the company report.
 */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(...UserRole::passportTeam());
    }

    /**
     * Read the company-wise report (accounts included, read only).
     */
    public function viewReport(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::OperationHead, UserRole::Accounts, UserRole::AccountsHead, UserRole::Admin, UserRole::SuperAdmin);
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
        return $user->hasRole(...UserRole::passportManagers());
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
