<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Staff management rules, based on role rank (see UserRole::canManage()).
 */
class UserPolicy
{
    /**
     * Whether the user may list staff accounts.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->assignableRoles() !== [];
    }

    /**
     * Whether the user may create an account with the given role.
     */
    public function create(User $user, UserRole $role): Response
    {
        return $this->assignRole($user, $role);
    }

    /**
     * Whether the user may give the given role to an account.
     */
    public function assignRole(User $user, UserRole $role): Response
    {
        return in_array($role, $user->role->assignableRoles(), true)
            ? Response::allow()
            : Response::deny("You may not assign the {$role->label()} role.");
    }

    /**
     * Whether the user may edit the target account.
     */
    public function update(User $user, User $target): Response
    {
        return $this->manage($user, $target);
    }

    /**
     * Whether the user may set a new password for the target account.
     */
    public function resetPassword(User $user, User $target): Response
    {
        return $this->manage($user, $target);
    }

    /**
     * Only users of a strictly lower rank may be managed, never oneself.
     */
    protected function manage(User $user, User $target): Response
    {
        if ($user->is($target)) {
            return Response::deny('You cannot manage your own account here.');
        }

        return $user->canManage($target)
            ? Response::allow()
            : Response::deny('You may not manage this account.');
    }
}
