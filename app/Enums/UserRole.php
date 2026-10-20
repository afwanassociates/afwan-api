<?php

namespace App\Enums;

/**
 * Staff roles and the hierarchy, all in this one file:
 *
 *   1 super_admin    everything, including admins and system settings
 *   2 admin          passport and workflow work, companies, countries, reports;
 *                    manages accounts heads, accounts and data entry users
 *   3 accounts_head  accounts and finance, approvals; manages accounts users
 *   4 accounts       accounts and finance; read-only passports and workflow
 *   5 data_entry     passport entry and every workflow step
 *
 * Who may enter which work area (areas()) and who may manage whom (manages())
 * are listed explicitly, not derived from the rank, so a higher role never
 * gets another team's area by accident. To add a role: add a case here, its
 * label, rank, areas and managers, then a route group for its work area.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case AccountsHead = 'accounts_head';
    case Accounts = 'accounts';
    case DataEntry = 'data_entry';

    /**
     * Human readable name of the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::AccountsHead => 'Accounts & Finance Head',
            self::Accounts => 'Accounts Team',
            self::DataEntry => 'Data Entry Team',
        };
    }

    /**
     * Position in the hierarchy, for ordering lists (highest first).
     */
    public function rank(): int
    {
        return match ($this) {
            self::SuperAdmin => 100,
            self::Admin => 80,
            self::AccountsHead => 60,
            self::Accounts => 20,
            self::DataEntry => 10,
        };
    }

    /**
     * The work areas (route groups "role:<area>") this role may enter.
     *
     * @return list<UserRole>
     */
    public function areas(): array
    {
        return match ($this) {
            self::SuperAdmin => self::cases(),
            self::Admin => [self::Admin, self::AccountsHead, self::Accounts, self::DataEntry],
            self::AccountsHead => [self::AccountsHead, self::Accounts],
            self::Accounts => [self::Accounts],
            self::DataEntry => [self::DataEntry],
        };
    }

    /**
     * The roles whose users this role may create and manage.
     *
     * @return list<UserRole>
     */
    public function manages(): array
    {
        return match ($this) {
            self::SuperAdmin => [self::Admin, self::AccountsHead, self::Accounts, self::DataEntry],
            self::Admin => [self::AccountsHead, self::Accounts, self::DataEntry],
            self::AccountsHead => [self::Accounts],
            self::Accounts, self::DataEntry => [],
        };
    }

    /**
     * Whether this role may enter the given work area.
     */
    public function canEnter(UserRole $area): bool
    {
        return in_array($area, $this->areas(), true);
    }

    /**
     * Whether a user with this role may manage a user with the target role.
     * Nobody may manage a super admin, not even another super admin.
     */
    public function canManage(UserRole $target): bool
    {
        return in_array($target, $this->manages(), true);
    }

    /**
     * Roles that approve accounts entries (cash receipts, payments, transfers
     * and hand-entered ledger lines).
     *
     * @return list<UserRole>
     */
    public static function approvers(): array
    {
        return [self::AccountsHead, self::Admin, self::SuperAdmin];
    }

    /**
     * Roles that work in accounts: parties, cash, ledgers.
     *
     * @return list<UserRole>
     */
    public static function accountsTeam(): array
    {
        return [self::Accounts, self::AccountsHead, self::Admin, self::SuperAdmin];
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
     * Roles a user with this role may assign to others, highest first.
     *
     * @return list<UserRole>
     */
    public function assignableRoles(): array
    {
        return $this->manages();
    }
}
