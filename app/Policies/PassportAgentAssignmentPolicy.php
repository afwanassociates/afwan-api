<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Models\User;

/**
 * Data entry assigns (and changes) a passport's recruitment agent; the operations head
 * approves it or returns it for correction (never one they made); the accounts
 * head decides whether a changed passport still counts for the old agent.
 */
class PassportAgentAssignmentPolicy
{
    public function assign(User $user, PassportEntry $passport): bool
    {
        return $user->hasRole(UserRole::DataEntry) && ! $passport->isReturned();
    }

    /**
     * The Agent Approvals list.
     */
    public function viewApprovals(User $user): bool
    {
        return $user->hasRole(...UserRole::passportManagers());
    }

    public function approve(User $user, PassportAgentAssignment $assignment): bool
    {
        return $this->viewApprovals($user) && $assignment->assigned_by !== $user->id;
    }

    /**
     * The Agent Changes list and its Yes / No.
     */
    public function decide(User $user): bool
    {
        return $user->hasRole(UserRole::AccountsHead);
    }
}
