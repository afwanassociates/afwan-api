<?php

namespace App\Policies;

use App\Enums\MedicalResult;
use App\Enums\UserRole;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;

/**
 * data_entry views and records medicals and edits its own records. Re-testing
 * an unfit passport, editing others' records, deleting, and any medical
 * change once a later step (calling onwards) has records are for admin and
 * super_admin only.
 *
 * (Since admins left passport entry, these corrections belong to the operations head.)
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

    public function delete(User $user, MedicalRecord $record): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Re-tests, editing anyone's record and deleting: the operations head.
     */
    protected function isAdmin(User $user): bool
    {
        return $user->hasRole(...UserRole::passportManagers());
    }
}
