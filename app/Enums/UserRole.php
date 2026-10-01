<?php

namespace App\Enums;

/**
 * Staff roles. To add a role, add a case here with its label and rank,
 * then add a route group for its work area. No migration is needed.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case DataEntry = 'data_entry';
    case Accounts = 'accounts';

    /**
     * Human readable name of the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::DataEntry => 'Data Entry Team',
            self::Accounts => 'Accounts Team',
        };
    }

    /**
     * Position in the hierarchy. A higher rank manages lower ranks.
     */
    public function rank(): int
    {
        return match ($this) {
            self::SuperAdmin => 100,
            self::Admin => 80,
            self::DataEntry => 10,
            self::Accounts => 10,
        };
    }

    /**
     * All role values as strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Whether a user with this role may manage a user with the target role.
     * Nobody may manage a super admin, not even another super admin.
     */
    public function canManage(UserRole $target): bool
    {
        return $target !== self::SuperAdmin && $this->rank() > $target->rank();
    }

    /**
     * Roles a user with this role may assign to others.
     *
     * @return list<UserRole>
     */
    public function assignableRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (UserRole $role): bool => $this->canManage($role),
        ));
    }
}
