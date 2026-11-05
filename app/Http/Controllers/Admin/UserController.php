<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    /**
     * Staff accounts the caller may see: super admins see everyone, others see
     * the accounts they can manage plus themselves.
     */
    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $caller = $request->user();

        $users = User::query()
            ->unless($caller->hasRole(UserRole::SuperAdmin), fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->whereIn('role', array_column($caller->role->assignableRoles(), 'value'))
                    ->orWhereKey($caller->getKey()),
            ))
            ->when($request->has('role'), fn (Builder $query) => $query->where('role', $request->enum('role', UserRole::class)))
            ->when($request->has('is_active'), fn (Builder $query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return UserResource::collection($users);
    }

    /**
     * Create a staff account with a role the caller may assign.
     */
    public function store(StoreUserRequest $request): UserResource
    {
        $role = $request->enum('role', UserRole::class);

        Gate::authorize('create', [User::class, $role]);

        $user = new User($request->safe()->only(['name', 'email', 'password']));
        $user->role = $role;
        $user->is_active = true;
        $user->created_by = $request->user()->id;
        $user->save();

        return new UserResource($user);
    }

    /**
     * Update a staff account. Deactivating it logs the user out everywhere.
     */
    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        if ($request->has('role')) {
            $role = $request->enum('role', UserRole::class);

            if ($role !== $user->role) {
                Gate::authorize('assignRole', [User::class, $role]);
            }

            $user->role = $role;
        }

        if ($request->has('is_active')) {
            $user->is_active = $request->boolean('is_active');
        }

        $user->fill($request->safe()->only(['name', 'email']));
        $deactivated = $user->isDirty('is_active') && ! $user->is_active;
        $user->save();

        if ($deactivated) {
            $user->logOutEverywhere();
        }

        return new UserResource($user);
    }

    /**
     * Set a new password and log the user out everywhere.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user): Response
    {
        $user->password = $request->validated('password');
        $user->save();

        $user->logOutEverywhere();

        return response()->noContent();
    }
}
