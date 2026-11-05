<?php

use App\Enums\PartyKind;
use App\Enums\PartyType;
use App\Models\Company;
use App\Models\Party;
use App\Models\PassportEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Laravel\Sanctum\Sanctum;

describe('references', function () {
    it('lets data entry quick-add a reference as a client party, normalizing the name and setting created_by', function () {
        $clerk = User::factory()->dataEntry()->create();
        Sanctum::actingAs($clerk);

        $this->postJson('/api/data-entry/references', ['type' => 'person', 'name' => '  Karim   Uddin ', 'phone' => '01711000000'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Karim Uddin')
            ->assertJsonPath('data.type_label', 'Person')
            ->assertJsonPath('data.phone', '01711000000')
            ->assertJsonPath('data.code', 'PT-0001')
            ->assertJsonPath('data.rl_number', null);

        expect(Party::sole())
            ->created_by->toBe($clerk->id)
            ->type->toBe(PartyType::Client)
            ->kind->toBe(PartyKind::Person)
            ->mobile->toBe('01711000000')
            ->address->toBeNull()
            ->nid_passport->toBeNull();
    });

    it('requires a phone, and an RL number for an agency only', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/references', ['type' => 'person', 'name' => 'Karim'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'Enter the phone number.']);
        $this->postJson('/api/data-entry/references', ['type' => 'agency', 'name' => 'Sea Star', 'phone' => '01711000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rl_number' => 'Enter the RL number of the agency.']);
        $this->postJson('/api/data-entry/references', ['type' => 'person', 'name' => 'Karim', 'phone' => '01711000000', 'rl_number' => 'RL-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rl_number' => 'Only agencies have an RL number.']);

        $this->postJson('/api/data-entry/references', ['type' => 'agency', 'name' => 'Sea Star', 'phone' => '01711000000', 'rl_number' => ' rl-1234 '])
            ->assertCreated()
            ->assertJsonPath('data.type', 'agency')
            ->assertJsonPath('data.rl_number', 'RL-1234');
        $this->postJson('/api/data-entry/references', ['type' => 'agency', 'name' => 'Other', 'phone' => '01711000000', 'rl_number' => 'RL-1234'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rl_number' => 'A party with this RL number already exists.']);
    });

    it('allows the same name once as a person and once as an agency, but not twice in one kind', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/references', ['type' => 'person', 'name' => 'Karim Uddin', 'phone' => '01711000000'])->assertCreated();
        $this->postJson('/api/data-entry/references', ['type' => 'agency', 'name' => 'Karim Uddin', 'phone' => '01711000000', 'rl_number' => 'RL-1'])->assertCreated();

        $this->postJson('/api/data-entry/references', ['type' => 'person', 'name' => 'KARIM  uddin', 'phone' => '01711000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'A person with this name already exists.']);
    });

    it('stores a reference created without a type as a person', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/references', ['name' => 'New Agent', 'phone' => '01711000000'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'person');
    });

    it('rejects an unknown type', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson('/api/data-entry/references', ['type' => 'friend', 'name' => 'X', 'phone' => '01711000000'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    });

    it('lookup lists active clients only, filters by type and returns at most 20 ordered by name', function () {
        Party::factory()->person()->count(25)->create();
        Party::factory()->agency()->count(3)->create();
        Party::factory()->person()->inactive()->create(['name' => 'Aaa Inactive']);
        Party::factory()->owner()->create(['name' => 'Aaa Owner']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $rows = $this->getJson('/api/data-entry/references?type=person')->assertOk()->assertJsonCount(20, 'data')->json('data');

        expect(collect($rows)->pluck('type')->unique()->all())->toBe(['person'])
            ->and(collect($rows)->pluck('name'))->not->toContain('Aaa Inactive');

        $this->getJson('/api/data-entry/references?type=agency')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/data-entry/references?q=aaa')->assertOk()->assertJsonCount(0, 'data');
    });

    it('lookup searches by name, phone, party ID and RL number', function () {
        $star = Party::factory()->agency()->create(['name' => 'Sea Star Overseas', 'rl_number' => 'RL-777', 'mobile' => '01811000000']);
        Party::factory()->person()->create(['name' => 'Abdul Karim', 'mobile' => '01911000000']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        foreach (['star', 'RL-777', '01811', $star->code] as $q) {
            $this->getJson('/api/data-entry/references?q='.urlencode($q))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.name', 'Sea Star Overseas');
        }
    });

    it('forbids data entry from editing or listing all references, and nobody deletes one', function () {
        $reference = Party::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/references/{$reference->id}", ['is_active' => false])->assertForbidden();
        $this->getJson('/api/data-entry/references?all=1')->assertForbidden();
        $this->deleteJson("/api/data-entry/references/{$reference->id}")->assertMethodNotAllowed();
    });

    it('keeps deactivating a reference from data entry and admins', function (string $role) {
        $reference = Party::factory()->create();
        PassportEntry::factory()->create(['reference_id' => $reference->id]);
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->patchJson("/api/data-entry/references/{$reference->id}", ['is_active' => false])->assertForbidden();
        expect($reference->fresh())->is_active->toBeTrue();
    })->with(['admin', 'superAdmin', 'dataEntry']);

    it('cannot delete a party that has passport entries', function () {
        $reference = Party::factory()->create();
        PassportEntry::factory()->create(['reference_id' => $reference->id]);

        expect(fn () => $reference->delete())->toThrow(QueryException::class);
    });

    it('rejects the accounts role and guests', function () {
        $this->getJson('/api/data-entry/references')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson('/api/data-entry/references')->assertForbidden();
        $this->postJson('/api/data-entry/references', ['type' => 'person', 'name' => 'X', 'phone' => '01711000000'])->assertForbidden();
    });

    it('lets the operations head deactivate and list inactive references', function () {
        $reference = Party::factory()->create();
        PassportEntry::factory()->create(['reference_id' => $reference->id]);
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->patchJson("/api/data-entry/references/{$reference->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->getJson('/api/data-entry/references?all=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $reference->id)
            ->assertJsonPath('meta.total', 1);
    });

    it('does not let the operations head edit an owner as a reference', function () {
        $owner = Party::factory()->owner()->create();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->patchJson("/api/data-entry/references/{$owner->id}", ['is_active' => false])->assertForbidden();
    });
});

describe('companies', function () {
    it('lets data entry create a company and rejects a duplicate name in any case', function () {
        $clerk = User::factory()->dataEntry()->create();
        Sanctum::actingAs($clerk);

        $this->postJson('/api/data-entry/companies', ['name' => '  Gulf   Star Catering ', 'quota' => 10, 'country_code' => 'MY'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Gulf Star Catering');
        expect(Company::sole()->created_by)->toBe($clerk->id);

        $this->postJson('/api/data-entry/companies', ['name' => 'gulf star catering', 'quota' => 10, 'country_code' => 'MY'])
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

    it('lets the operations head rename, deactivate and soft delete a company', function () {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->patchJson("/api/admin/companies/{$company->id}", ['name' => 'Renamed Co', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Co')
            ->assertJsonPath('data.is_active', false);
        // The old data-entry copy of the route works the same for them.
        $this->patchJson("/api/data-entry/companies/{$company->id}", ['name' => 'Again'])->assertOk();

        $this->deleteJson("/api/admin/companies/{$company->id}")->assertNoContent();
        $this->assertSoftDeleted($company);
    });

    it('keeps company management from admins: they read the reports only', function (string $role) {
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/admin/companies')->assertForbidden();
        $this->patchJson("/api/admin/companies/{$company->id}", ['is_active' => false])->assertForbidden();
        $this->deleteJson("/api/admin/companies/{$company->id}")->assertForbidden();
        $this->getJson('/api/reports/companies')->assertOk();
    })->with(['admin', 'superAdmin']);
});
