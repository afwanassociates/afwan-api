<?php

use App\Enums\PartyKind;
use App\Models\CashReceipt;
use App\Models\Party;
use App\Models\PassportEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('reference management', function () {
    it('lets the operations head list, create and edit references (client parties)', function () {
        $head = User::factory()->operationHead()->create();
        Sanctum::actingAs($head);

        $id = $this->postJson('/api/admin/references', [
            'type' => 'agency',
            'name' => '  Sea  Star Overseas ',
            'phone' => '01711-000000',
            'rl_number' => 'RL-1001',
            // Ignored: new references are always active.
            'is_active' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sea Star Overseas')
            ->assertJsonPath('data.type', 'agency')
            ->assertJsonPath('data.rl_number', 'RL-1001')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.passports_count', 0)
            ->json('data.id');

        expect(Party::find($id))->created_by->toBe($head->id)->code->toBe('PT-0001');

        $this->patchJson("/api/admin/references/{$id}", ['phone' => '01811-111111', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.phone', '01811-111111')
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/admin/references?is_active=0&type=agency&q=sea star')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$id]);
    });

    it('turns an agency into a person, dropping its RL number, and needs one the other way', function () {
        $agency = Party::factory()->agency()->create(['rl_number' => 'RL-1', 'agency_owner_name' => 'Mr Owner']);
        $person = Party::factory()->person()->create();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->patchJson("/api/admin/references/{$agency->id}", ['type' => 'person'])->assertOk()->assertJsonPath('data.rl_number', null);
        expect($agency->fresh())->kind->toBe(PartyKind::Person)->agency_owner_name->toBeNull();

        $this->patchJson("/api/admin/references/{$person->id}", ['type' => 'agency'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('rl_number');
    });

    it('lists every client with its passport count, active and inactive, owners left out', function () {
        $busy = Party::factory()->create(['name' => 'Abdul Karim']);
        PassportEntry::factory()->count(2)->create(['reference_id' => $busy->id]);
        $idle = Party::factory()->create(['name' => 'Bashir Ahmed', 'is_active' => false]);
        Party::factory()->owner()->create(['name' => 'Aaa Owner']);
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->getJson('/api/admin/references')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$busy->id, $idle->id])
            ->assertJsonPath('data.0.passports_count', 2)
            ->assertJsonPath('data.1.passports_count', 0)
            ->assertJsonPath('meta.total', 2);
        $this->getJson('/api/admin/references?is_active=1')->assertJsonPath('data.*.id', [$busy->id]);
    });

    it('deletes a reference that has no passports and nothing in accounts', function () {
        $unused = Party::factory()->person()->create();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->getJson('/api/admin/references')->assertJsonPath('data.0.can_delete', true);
        $this->deleteJson("/api/admin/references/{$unused->id}")->assertNoContent();
        expect(Party::find($unused->id))->toBeNull();
    });

    it('refuses to delete a reference with passports, deleted ones included, and offers to deactivate', function () {
        $reference = Party::factory()->create();
        PassportEntry::factory()->create(['reference_id' => $reference->id])->delete();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->getJson('/api/admin/references')
            ->assertJsonPath('data.0.passports_count', 0)
            ->assertJsonPath('data.0.can_delete', false);
        $this->deleteJson("/api/admin/references/{$reference->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'reference_has_passports');
        expect(Party::find($reference->id))->not->toBeNull();

        $this->patchJson("/api/admin/references/{$reference->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    });

    it('refuses to delete a reference with records in accounts', function () {
        $withReceipt = Party::factory()->create(['name' => 'A With Receipt']);
        CashReceipt::factory()->create(['party_id' => $withReceipt->id]);
        $withOpening = Party::factory()->create(['name' => 'B With Opening', 'opening_balance' => 500]);
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->getJson('/api/admin/references')->assertJsonPath('data.*.can_delete', [false, false]);
        foreach ([$withReceipt, $withOpening] as $party) {
            $this->deleteJson("/api/admin/references/{$party->id}")
                ->assertUnprocessable()
                ->assertJsonPath('code', 'reference_has_accounts');
        }
        expect(Party::count())->toBe(2);
    });

    it('never deletes an owner from the references page', function () {
        $owner = Party::factory()->owner()->create();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->deleteJson("/api/admin/references/{$owner->id}")->assertForbidden();
    });

    it('rejects a duplicate name of the same kind', function () {
        Party::factory()->agency()->create(['name' => 'Sea Star Overseas']);
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->postJson('/api/admin/references', ['type' => 'agency', 'name' => 'SEA STAR OVERSEAS', 'phone' => '01711000000', 'rl_number' => 'RL-9'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'An agency with this name already exists.']);
    });

    it('refuses reference management to everyone but the operations head', function (string $role) {
        $reference = Party::factory()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/admin/references')->assertForbidden();
        $this->postJson('/api/admin/references', ['type' => 'person', 'name' => 'X', 'phone' => '01711000000'])->assertForbidden();
        $this->patchJson("/api/admin/references/{$reference->id}", ['is_active' => false])->assertForbidden();
        $this->deleteJson("/api/admin/references/{$reference->id}")->assertForbidden();
    })->with(['dataEntry', 'accounts', 'accountsHead', 'admin', 'superAdmin']);

    it('rejects unauthenticated requests', function () {
        $this->getJson('/api/admin/references')->assertUnauthorized();
    });
});
