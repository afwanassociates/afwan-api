<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PassportEntry;
use App\Models\User;

/**
 * data_entry views and creates entries and edits its own. admin and
 * super_admin edit any entry and are the only ones who delete.
 */
class PassportEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::Admin, UserRole::SuperAdmin);
    }

    /**
     * Read the All Passports overview (accounts included, read only).
     */
    public function viewOverview(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::Accounts, UserRole::Admin, UserRole::SuperAdmin);
    }

    public function view(User $user, PassportEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, PassportEntry $entry): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin)
            || ($user->hasRole(UserRole::DataEntry) && $entry->created_by === $user->id);
    }

    /**
     * Save the medical slip (date and centre) of any passport.
     */
    public function updateMedicalSlip(User $user, PassportEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Set the medical date of any passport (the result lock is checked in
     * App\Services\MedicalService).
     */
    public function updateMedicalDate(User $user, PassportEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    /**
     * View or download the scan copy of any passport.
     */
    public function viewScan(User $user, PassportEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Upload or replace the scan copy of any passport.
     */
    public function uploadScan(User $user, PassportEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Mark any fit passport Online (the medical and Calling rules
     * are checked in App\Services\WorkflowService).
     */
    public function updateEntryMode(User $user, PassportEntry $entry): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, PassportEntry $entry): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin);
    }
}
