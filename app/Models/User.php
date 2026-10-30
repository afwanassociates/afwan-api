<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Role and is_active are deliberately not fillable: assign them explicitly.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * The staff member who created this account.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the user has any of the given roles.
     */
    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Whether this user may manage the other user, based on role rank.
     * Nobody manages themselves.
     */
    public function canManage(User $other): bool
    {
        return $this->isNot($other) && $this->role->canManage($other->role);
    }

    /**
     * End every session and API token of this user, so they are logged out
     * immediately (used after deactivation or a password reset).
     */
    public function logOutEverywhere(): void
    {
        DB::table(config('session.table', 'sessions'))->where('user_id', $this->getKey())->delete();

        $this->tokens()->delete();

        $this->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}
