<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PassportEntry;
use App\Models\User;
use App\Support\Workflow;

/**
 * The passport team (data entry and the operations head) views and creates
 * entries; data entry edits its own. Only the operations head edits any entry
 * and returns a passport to its owner (or undoes that). Nobody deletes a
 * passport. Admins see passports only through the read-only reports.
 */
class PassportEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(...UserRole::passportTeam());
    }

    /**
     * Read the All Passports overview (accounts included, read only).
     */
    public function viewOverview(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::OperationHead, UserRole::Accounts, UserRole::AccountsHead, UserRole::Admin, UserRole::SuperAdmin);
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
        return $user->hasRole(...UserRole::passportManagers())
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

    /**
     * Passports are never deleted, by anyone: a passport handed back is returned instead.
     */
    public function delete(User $user, PassportEntry $entry): bool
    {
        return false;
    }

    /**
     * Return the passport to its owner, at any step until the flight is
     * confirmed (the operations head).
     */
    public function returnPassport(User $user, PassportEntry $entry): bool
    {
        return $user->hasRole(...UserRole::passportManagers())
            && ! $entry->isReturned()
            && $entry->current_stage !== Workflow::COMPLETED;
    }

    /**
     * Undo the return: the passport goes back to the step it was at (the operations head).
     */
    public function undoReturn(User $user, PassportEntry $entry): bool
    {
        return $user->hasRole(...UserRole::passportManagers()) && $entry->isReturned();
    }

    /**
     * Read the Returned Passports list (every staff role, read only).
     */
    public function viewReturned(User $user): bool
    {
        return $this->viewOverview($user);
    }
}
