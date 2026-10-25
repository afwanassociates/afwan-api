<?php

use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('company admin CRUD', function () {
    it('lets admin and super admin list, create, show, edit and delete companies', function (string $role) {
        $admin = User::factory()->{$role}()->create();
        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/admin/companies', [
            'name' => 'Desert Rose Construction',
            'country_code' => 'my',
            'quota' => 25,
            // Ignored: new companies are always active.
            'is_active' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.country.code', 'MY')
            ->assertJsonPath('data.quota', 25)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.agent_name')
            ->assertJsonPath('data.passports_count', 0)
            ->json('data.id');

        expect(Company::find($id)->created_by)->toBe($admin->id);

        $this->getJson("/api/admin/companies/{$id}")
            ->assertOk()
            ->assertJsonPath('data.quota', 25);

        $this->patchJson("/api/admin/companies/{$id}", ['quota' => 30, 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.quota', 30)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.name', 'Desert Rose Construction');

        $this->getJson('/api/admin/companies?is_active=0&q=desert rose')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$id]);

        $this->deleteJson("/api/admin/companies/{$id}")->assertNoContent();

        expect(Company::withTrashed()->find($id)->trashed())->toBeTrue();
    })->with(['admin', 'superAdmin']);

    it('blocks deleting a company with passports, deleted passports included', function () {
        $company = Company::factory()->create();
        PassportEntry::factory()->create(['company_id' => $company->id])->delete();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/admin/companies/{$company->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'company_has_passports');
        // The older data-entry copy of the route is closed to admins now.
        $this->deleteJson("/api/data-entry/companies/{$company->id}")->assertForbidden();

        expect($company->fresh()->trashed())->toBeFalse();

        // Deactivating works instead.
        $this->patchJson("/api/admin/companies/{$company->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    });

    it('refuses the admin endpoints to data entry and accounts', function (string $role) {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/admin/companies')->assertForbidden();
        $this->getJson("/api/admin/companies/{$company->id}")->assertForbidden();
        $this->postJson('/api/admin/companies', ['name' => 'X Co', 'country_code' => 'MY', 'quota' => 1])->assertForbidden();
        $this->patchJson("/api/admin/companies/{$company->id}", ['quota' => 5])->assertForbidden();
        $this->deleteJson("/api/admin/companies/{$company->id}")->assertForbidden();
    })->with(['dataEntry', 'accounts']);

    it('rejects unauthenticated requests', function () {
        $this->getJson('/api/admin/companies')->assertUnauthorized();
    });
});

describe('data entry quick add', function () {
    it('keeps search and Quick Add for data entry, with the name, quota and country', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/companies', [
            'name' => 'Quick Co',
            'country_code' => 'MY',
            'quota' => 50,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Quick Co')
            ->assertJsonPath('data.quota', 50)
            ->assertJsonPath('data.is_active', true);

        $this->getJson('/api/data-entry/companies?q=quick')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Quick Co']);
    });

    it('does not let data entry edit or delete companies', function () {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/companies/{$company->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/data-entry/companies/{$company->id}")->assertForbidden();
    });
});
