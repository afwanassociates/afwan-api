<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\RecruitmentAgent;
use App\Models\User;

/**
 * Only the operations head adds, edits and (de)activates recruitment agents. The
 * passport team picks an agent when assigning a passport; the accounts team picks
 * one on a cash payment and sees its payments.
 */
class RecruitmentAgentPolicy
{
    /**
     * Search active agents for a dropdown.
     */
    public function lookup(User $user): bool
    {
        return $user->hasRole(...UserRole::passportTeam(), ...UserRole::accountsTeam());
    }

    /**
     * The full list (inactive ones included) and the agent pages.
     */
    public function manage(User $user): bool
    {
        return $user->hasRole(...UserRole::passportManagers());
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, RecruitmentAgent $agent): bool
    {
        return $this->manage($user);
    }

    /**
     * The agent report (passport counts): the operations head and the accounts head.
     */
    public function viewReport(User $user): bool
    {
        return $user->hasRole(UserRole::OperationHead, UserRole::AccountsHead);
    }

    /**
     * An agent's payments and paid total: the accounts team only.
     */
    public function viewPayments(User $user): bool
    {
        return $user->hasRole(...UserRole::accountsTeam());
    }
}
