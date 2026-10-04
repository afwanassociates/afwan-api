<?php

use App\Enums\ReferenceType;
use App\Models\Company;
use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;

describe('references', function () {
    it('lets data entry create a reference, normalizing the name and setting created_by', function () {
        $clerk = User::factory()->dataEntry()->create();
        Sanctum::actingAs($clerk);

        $this->postJson('/api/data-entry/references', ['type' => 'person', 'name' => '  Karim   Uddin ', 'phone' => '01711000000'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Karim Uddin')
            ->assertJsonPath('data.type_label', 'Person');

        expect(Reference::sole()->created_by)->toBe($clerk->id);
    });

    it('allows the same name once as a person and once as an agency, but not twice in one type', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/references', ['type' => 'person', 'name' => 'Karim Uddin'])->assertCreated();
        $this->postJson('/api/data-entry/references', ['type' => 'agency', 'name' => 'Karim Uddin'])->assertCreated();

        $this->postJson('/api/data-entry/references', ['type' => 'person', 'name' => 'KARIM  uddin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'A reference of this type with this name already exists.']);
    });

    it('rejects an unknown type', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/references', ['type' => 'friend', 'name' => 'X'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    });

    it('lookup filters by type, hides inactive references and returns at most 20 ordered by name', function () {
        Reference::factory()->person()->count(25)->create();
        Reference::factory()->agency()->count(3)->create();
        Reference::factory()->person()->inactive()->create(['name' => 'Aaa Inactive']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $rows = $this->getJson('/api/data-entry/references?type=person')->assertOk()->assertJsonCount(20, 'data')->json('data');

        expect(collect($rows)->pluck('type')->unique()->all())->toBe(['person'])
            ->and(collect($rows)->pluck('name')->all())->toBe(collect($rows)->pluck('name')->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all())
            ->and(collect($rows)->pluck('name'))->not->toContain('Aaa Inactive');

        $this->getJson('/api/data-entry/references?type=agency')->assertOk()->assertJsonCount(3, 'data');
    });

    it('lookup searches by name', function () {
        Reference::factory()->agency()->create(['name' => 'Sea Star Overseas']);
        Reference::factory()->person()->create(['name' => 'Abdul Karim']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/references?q=star')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Sea Star Overseas');
    });

    it('forbids data entry from editing, deleting or listing all references', function () {
        $reference = Reference::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/references/{$reference->id}", ['is_active' => false])->assertForbidden();
        $this->deleteJson("/api/data-entry/references/{$reference->id}")->assertForbidden();
        $this->getJson('/api/data-entry/references?all=1')->assertForbidden();
    });

    it('lets admins deactivate, list inactive and soft delete a reference that has entries', function (string $role) {
        $reference = Reference::factory()->create();
        PassportEntry::factory()->create(['reference_id' => $reference->id]);
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->patchJson("/api/data-entry/references/{$reference->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->getJson('/api/data-entry/references?all=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $reference->id)
            ->assertJsonPath('meta.total', 1);

        $this->deleteJson("/api/data-entry/references/{$reference->id}")->assertNoContent();
        $this->assertSoftDeleted($reference);
    })->with(['admin', 'superAdmin']);

    it('cannot hard delete a reference that has passport entries', function () {
        $reference = Reference::factory()->create();
        PassportEntry::factory()->create(['reference_id' => $reference->id]);

        expect(fn () => $reference->forceDelete())->toThrow(QueryException::class);
    });

    it('rejects the accounts role and guests', function () {
        $this->getJson('/api/data-entry/references')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson('/api/data-entry/references')->assertForbidden();
        $this->postJson('/api/data-entry/references', ['type' => ReferenceType::Person->value, 'name' => 'X'])->assertForbidden();
    });
});

describe('companies', function () {
    it('lets data entry create a company and rejects a duplicate name in any case', function () {
        $clerk = User::factory()->dataEntry()->create();
        Sanctum::actingAs($clerk);

        $this->postJson('/api/data-entry/companies', ['name' => '  Gulf   Star Catering '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Gulf Star Catering');
        expect(Company::sole()->created_by)->toBe($clerk->id);

        $this->postJson('/api/data-entry/companies', ['name' => 'gulf star catering'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'A company with this name already exists.']);
    });

    it('lookup hides inactive companies, searches and returns at most 20', function () {
        Company::factory()->count(22)->create();
        Company::factory()->create(['name' => 'Pearl Coast Facilities']);
        Company::factory()->inactive()->create(['name' => 'Pearl Old Facilities']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/companies')->assertOk()->assertJsonCount(20, 'data');
        $this->getJson('/api/data-entry/companies?q=pearl')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Pearl Coast Facilities');
    });

    it('forbids data entry from editing or deleting a company', function () {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/companies/{$company->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/data-entry/companies/{$company->id}")->assertForbidden();
    });

    it('lets admins rename, deactivate and soft delete a company', function (string $role) {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->patchJson("/api/data-entry/companies/{$company->id}", ['name' => 'Renamed Co', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Co')
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/data-entry/companies/{$company->id}")->assertNoContent();
        $this->assertSoftDeleted($company);
    })->with(['admin', 'superAdmin']);
});
