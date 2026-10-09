<?php

namespace App\Policies;

use App\Enums\MedicalResult;
use App\Enums\UserRole;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;

/**
 * data_entry views and records medicals and edits its own records. Re-testing
 * an unfit passport, editing others' records and deleting are for admin and
 * super_admin only.
 */
class MedicalRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::Admin, UserRole::SuperAdmin);
    }

    /**
     * Record a medical for the passport (load currentMedical to avoid a query).
     */
    public function create(User $user, PassportEntry $passport): bool
    {
        if ($passport->currentMedical?->result === MedicalResult::Unfit) {
            return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin);
        }

        return $this->viewAny($user);
    }

    public function update(User $user, MedicalRecord $record): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin)
            || ($user->hasRole(UserRole::DataEntry) && $record->created_by === $user->id);
    }

    public function delete(User $user, MedicalRecord $record): bool
    {
        return $user->hasRole(UserRole::Admin, UserRole::SuperAdmin);
    }
}
