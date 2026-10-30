<?php

namespace App\Policies;

use App\Enums\MedicalResult;
use App\Enums\UserRole;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;

/**
 * data_entry views and records medicals and edits its own records. Re-testing
 * an unfit passport, editing others' records and any medical change once a
 * later step (calling onwards) has records are for the operations head.
 * Nobody deletes a medical record, and a returned passport takes no medical.
 */
class MedicalRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(...UserRole::passportTeam());
    }

    /**
     * Record a medical for the passport (load currentMedical to avoid a query).
     */
    public function create(User $user, PassportEntry $passport): bool
    {
        if ($passport->isReturned()) {
            return false;
        }

        if ($passport->currentMedical?->result === MedicalResult::Unfit || $passport->hasStepRecords()) {
            return $this->isAdmin($user);
        }

        return $this->viewAny($user);
    }

    /**
     * Load passportEntry to avoid a query.
     */
    public function update(User $user, MedicalRecord $record): bool
    {
        return $this->isAdmin($user)
            || ($user->hasRole(UserRole::DataEntry)
                && $record->created_by === $user->id
                && ! $record->passportEntry?->hasStepRecords());
    }

    /**
     * Medical records are never deleted, by anyone.
     */
    public function delete(User $user, MedicalRecord $record): bool
    {
        return false;
    }

    /**
     * Re-tests and editing anyone's record: the operations head.
     */
    protected function isAdmin(User $user): bool
    {
        return $user->hasRole(...UserRole::passportManagers());
    }
}
