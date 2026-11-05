<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class StaffUserSeeder extends Seeder
{
    /**
     * Development accounts, one per role. All use the password "password".
     *
     * @var array<string, array{name: string, role: UserRole}>
     */
    protected array $accounts = [
        'superadmin@afwan.test' => ['name' => 'Super Admin', 'role' => UserRole::SuperAdmin],
        'admin@afwan.test' => ['name' => 'Admin', 'role' => UserRole::Admin],
        'dataentry@afwan.test' => ['name' => 'Data Entry', 'role' => UserRole::DataEntry],
        'accountshead@afwan.test' => ['name' => 'Accounts Head', 'role' => UserRole::AccountsHead],
        'operationhead@afwan.test' => ['name' => 'Operations Head', 'role' => UserRole::OperationHead],
        'accounts@afwan.test' => ['name' => 'Accounts', 'role' => UserRole::Accounts],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('StaffUserSeeder creates accounts with known passwords and will not run in production.');

            return;
        }

        foreach ($this->accounts as $email => $account) {
            $user = User::firstOrNew(['email' => $email]);
            $user->name = $account['name'];
            $user->password = 'password';
            $user->role = $account['role'];
            $user->is_active = true;
            $user->email_verified_at ??= now();
            $user->save();
        }
    }
}
