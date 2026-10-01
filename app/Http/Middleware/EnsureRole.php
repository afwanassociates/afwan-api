<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Allow the request when the user is active and has one of the given roles,
     * or a role that outranks one of them (admin passes "role:data_entry",
     * super_admin passes "role:admin"). With no roles, any active user passes.
     *
     * Usage: ->middleware('role:accounts') or ->middleware('role:data_entry,accounts')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        if (! $user->is_active) {
            abort(403, 'Your account has been deactivated.');
        }

        if ($roles === []) {
            return $next($request);
        }

        foreach ($roles as $role) {
            $required = UserRole::from($role);

            if ($user->role === $required || $user->role->canManage($required)) {
                return $next($request);
            }
        }

        abort(403, 'You do not have permission to access this area.');
    }
}
