<?php

namespace App\Policies;

use App\Enums\PartyType;
use App\Enums\UserRole;
use App\Models\Party;
use App\Models\User;

/**
 * The accounts team (accounts and the accounts head) keeps parties and their
 * ledgers. Client parties are also the passports' references: the passport
 * team searches them and quick-adds new ones, and the operations head edits
 * and (de)activates them like the accounts team does. Only the operations
 * head manages the references page; the accounts head deletes on the
 * Party / Owner page. Only a party or owner with no passports
 * and nothing in accounts is ever deleted; any other one is deactivated.
 * Admins have no parties.
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

    /**
     * Delete a party or owner on the Party / Owner page (the controller also
     * checks it has no passports and nothing in accounts).
     */
    public function delete(User $user, Party $party): bool
    {
        return $user->hasRole(...UserRole::partyDeleters());
    }

    /**
     * Search client parties in the passport form and quick-add one there.
     */
    public function searchReferences(User $user): bool
    {
        return $user->hasRole(...UserRole::passportTeam());
    }

    /**
     * Search client parties in the reports' filters and see the reference
     * report (accounts included, read only).
     */
    public function viewReport(User $user): bool
    {
        return $user->hasRole(UserRole::DataEntry, UserRole::OperationHead, UserRole::Accounts, UserRole::AccountsHead, UserRole::Admin, UserRole::SuperAdmin);
    }

    /**
     * The references page: every client party, inactive ones included.
     */
    public function manageReferences(User $user): bool
    {
        return $user->hasRole(...UserRole::passportManagers());
    }

    /**
     * Edit or (de)activate a client party from the references page.
     */
    public function updateReference(User $user, Party $party): bool
    {
        return $party->type === PartyType::Client && $this->manageReferences($user);
    }

    /**
     * Delete a client party from the references page (the controller also
     * checks it has no passports and nothing in accounts).
     */
    public function deleteReference(User $user, Party $party): bool
    {
        return $this->updateReference($user, $party);
    }
}
