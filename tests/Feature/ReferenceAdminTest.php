<?php

use App\Models\PassportEntry;
use App\Models\Reference;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('reference management', function () {
    it('lets the operations head list, create, edit and delete references', function () {
        $head = User::factory()->operationHead()->create();
        Sanctum::actingAs($head);

        $id = $this->postJson('/api/admin/references', [
            'type' => 'agency',
            'name' => '  Sea  Star Overseas ',
            'phone' => '01711-000000',
            // Ignored: new references are always active.
            'is_active' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Sea Star Overseas')
            ->assertJsonPath('data.type', 'agency')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.passports_count', 0)
            ->json('data.id');

        expect(Reference::find($id)->created_by)->toBe($head->id);

        $this->patchJson("/api/admin/references/{$id}", ['phone' => '01811-111111', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.phone', '01811-111111')
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/admin/references?is_active=0&type=agency&q=sea star')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$id]);

        $this->deleteJson("/api/admin/references/{$id}")->assertNoContent();
        expect(Reference::withTrashed()->find($id)->trashed())->toBeTrue();
    });

    it('lists every reference with its passport count, active and inactive', function () {
        $busy = Reference::factory()->create(['name' => 'Abdul Karim']);
        PassportEntry::factory()->count(2)->create(['reference_id' => $busy->id]);
        $idle = Reference::factory()->create(['name' => 'Bashir Ahmed', 'is_active' => false]);
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->getJson('/api/admin/references')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$busy->id, $idle->id])
            ->assertJsonPath('data.0.passports_count', 2)
            ->assertJsonPath('data.1.passports_count', 0)
            ->assertJsonPath('meta.total', 2);
        $this->getJson('/api/admin/references?is_active=1')->assertJsonPath('data.*.id', [$busy->id]);
    });

    it('blocks deleting a reference with passports, deleted passports included', function () {
        $reference = Reference::factory()->create();
        PassportEntry::factory()->create(['reference_id' => $reference->id])->delete();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->deleteJson("/api/admin/references/{$reference->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'reference_has_passports');
        expect($reference->fresh()->trashed())->toBeFalse();

        // Deactivating works instead.
        $this->patchJson("/api/admin/references/{$reference->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    });

    it('rejects a duplicate name of the same type', function () {
        Reference::factory()->agency()->create(['name' => 'Sea Star Overseas']);
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->postJson('/api/admin/references', ['type' => 'agency', 'name' => 'SEA STAR OVERSEAS'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'A reference with this name already exists.']);
    });

    it('refuses reference management to everyone but the operations head', function (string $role) {
        $reference = Reference::factory()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/admin/references')->assertForbidden();
        $this->postJson('/api/admin/references', ['type' => 'person', 'name' => 'X'])->assertForbidden();
        $this->patchJson("/api/admin/references/{$reference->id}", ['is_active' => false])->assertForbidden();
        $this->deleteJson("/api/admin/references/{$reference->id}")->assertForbidden();
    })->with(['dataEntry', 'accounts', 'accountsHead', 'admin', 'superAdmin']);

    it('rejects unauthenticated requests', function () {
        $this->getJson('/api/admin/references')->assertUnauthorized();
    });
});
