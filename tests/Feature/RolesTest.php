<?php

use App\Enums\UserRole;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('role hierarchy', function () {
    it('lets each role into its own areas only', function (string $role, array $allowed) {
        Sanctum::actingAs(User::factory()->role(UserRole::from($role))->create());

        foreach (['super-admin', 'admin', 'accounts-head', 'operation-head', 'accounts', 'data-entry'] as $area) {
            $this->getJson("/api/{$area}/dashboard")->assertStatus(in_array($area, $allowed, true) ? 200 : 403);
        }
    })->with([
        // Passport entry (data-entry) is for the data entry team and its head, not admins.
        // Accounts (parties, cash) is the accounts team's: admins only approve.
        'super admin' => ['super_admin', ['super-admin', 'admin']],
        'admin' => ['admin', ['admin']],
        'accounts head' => ['accounts_head', ['accounts-head', 'accounts']],
        'operations head' => ['operation_head', ['operation-head', 'data-entry']],
        'accounts' => ['accounts', ['accounts']],
        'data entry' => ['data_entry', ['data-entry']],
    ]);

    it('gives the accounts head the read-only passport reports, not passport entry', function () {
        Sanctum::actingAs(User::factory()->accountsHead()->create());

        $this->getJson('/api/reports/summary')->assertOk();
        $this->getJson('/api/reports/passports')->assertOk();
        $this->getJson('/api/data-entry/passports')->assertForbidden();
        $this->getJson('/api/admin/companies')->assertForbidden();
        $this->getJson('/api/accounts/parties')->assertOk();
    });

    it('lists the roles each role may assign', function (string $role, array $assignable) {
        Sanctum::actingAs(User::factory()->role(UserRole::from($role))->create());

        $this->getJson('/api/roles')->assertOk()->assertJsonPath('data.*.value', $assignable);
    })->with([
        'super admin' => ['super_admin', ['admin', 'accounts_head', 'operation_head', 'accounts', 'data_entry']],
        'admin' => ['admin', ['accounts_head', 'operation_head', 'accounts', 'data_entry']],
        'accounts head' => ['accounts_head', ['accounts']],
        'operations head' => ['operation_head', ['data_entry']],
        'accounts' => ['accounts', []],
        'data entry' => ['data_entry', []],
    ]);

    it('lets the accounts head manage accounts users only', function () {
        $head = User::factory()->accountsHead()->create();
        $clerk = User::factory()->accounts()->create();
        $entry = User::factory()->dataEntry()->create();
        Sanctum::actingAs($head);

        $this->getJson('/api/admin/users?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.total', 2); // the clerk and themselves
        $this->postJson('/api/admin/users', [
            'name' => 'New Clerk', 'email' => 'clerk2@afwan.test', 'role' => 'accounts',
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123',
        ])->assertCreated();
        $this->postJson('/api/admin/users', [
            'name' => 'New Entry', 'email' => 'entry2@afwan.test', 'role' => 'data_entry',
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123',
        ])->assertForbidden();
        $this->patchJson("/api/admin/users/{$clerk->id}", ['is_active' => false])->assertOk();
        $this->patchJson("/api/admin/users/{$entry->id}", ['is_active' => false])->assertForbidden();
        $this->patchJson("/api/admin/users/{$clerk->id}", ['role' => 'accounts_head'])->assertForbidden();
    });

    it('lets the operations head manage data entry users only', function () {
        $head = User::factory()->operationHead()->create();
        $entry = User::factory()->dataEntry()->create();
        $clerk = User::factory()->accounts()->create();
        $otherHead = User::factory()->operationHead()->create();
        Sanctum::actingAs($head);

        $this->getJson('/api/admin/users?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.total', 2); // the data entry user and themselves
        $this->postJson('/api/admin/users', [
            'name' => 'New Entry', 'email' => 'entry2@afwan.test', 'role' => 'data_entry',
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123',
        ])->assertCreated();
        $this->postJson('/api/admin/users', [
            'name' => 'New Clerk', 'email' => 'clerk2@afwan.test', 'role' => 'accounts',
            'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123',
        ])->assertForbidden();
        $this->patchJson("/api/admin/users/{$entry->id}", ['is_active' => false])->assertOk();
        $this->patchJson("/api/admin/users/{$clerk->id}", ['is_active' => false])->assertForbidden();
        $this->patchJson("/api/admin/users/{$otherHead->id}", ['is_active' => false])->assertForbidden();
        $this->patchJson("/api/admin/users/{$entry->id}", ['role' => 'operation_head'])->assertForbidden();
    });

    it('gives the operations head passport entry and reports, not accounts or companies admin', function () {
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->getJson('/api/data-entry/passports')->assertOk();
        $this->getJson('/api/reports/summary')->assertOk();
        $this->getJson('/api/accounts/parties')->assertForbidden();
        $this->getJson('/api/admin/companies')->assertForbidden();
    });

    it('lets an admin manage the operations head', function () {
        $head = User::factory()->operationHead()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/admin/users/{$head->id}", ['is_active' => false])->assertOk();
    });

    it('lets an admin manage accounts heads, but not other admins', function () {
        $head = User::factory()->accountsHead()->create();
        $other = User::factory()->admin()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/admin/users/{$head->id}", ['is_active' => false])->assertOk();
        $this->patchJson("/api/admin/users/{$other->id}", ['is_active' => false])->assertForbidden();
    });
});
