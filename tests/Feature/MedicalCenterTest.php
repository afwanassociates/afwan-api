<?php

use App\Enums\StepStatus;
use App\Models\MedicalCenter;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Today in Dhaka is 2026-05-01 (00:30), while UTC is still on 2026-04-30.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

describe('medical centre admin', function () {
    it('comes with the four centres from the migration', function () {
        expect(MedicalCenter::query()->orderBy('name')->pluck('name')->all())->toBe([
            'Catharsis Medical Center',
            'Meem Medical Center',
            'SKN Health Services',
            'Zain Medical Center',
        ]);
    });

    it('lets the operations head list, create, rename, deactivate and delete centres', function () {
        $admin = User::factory()->operationHead()->create();
        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/data-entry/medical-centers', ['name' => '  Popular   Diagnostic  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Popular Diagnostic')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.passports_count', 0)
            ->assertJsonPath('data.can_delete', true)
            ->json('data.id');

        expect(MedicalCenter::find($id)->created_by)->toBe($admin->id);

        $this->patchJson("/api/admin/medical-centers/{$id}", ['name' => 'Popular Diagnostic Centre', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Popular Diagnostic Centre')
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/admin/medical-centers?is_active=0')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$id]);
        $this->getJson('/api/admin/medical-centers?q=meem')
            ->assertJsonPath('data.*.name', ['Meem Medical Center']);

        $this->deleteJson("/api/admin/medical-centers/{$id}")->assertNoContent();

        expect(MedicalCenter::withTrashed()->find($id)->trashed())->toBeTrue();
    });

    it('deletes only a centre no passport names; one in use is deactivated instead', function () {
        $used = MedicalCenter::query()->where('name', 'Meem Medical Center')->sole();
        PassportEntry::factory()->create(['medical_center_id' => $used->id]);
        // A deleted passport still keeps its centre.
        $onDeleted = MedicalCenter::query()->where('name', 'Zain Medical Center')->sole();
        PassportEntry::factory()->create(['medical_center_id' => $onDeleted->id])->delete();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $rows = collect($this->getJson('/api/admin/medical-centers')->assertOk()->json('data'))->keyBy('name');
        expect($rows['Meem Medical Center']['passports_count'])->toBe(1)
            ->and($rows['Meem Medical Center']['can_delete'])->toBeFalse()
            ->and($rows['Zain Medical Center']['passports_count'])->toBe(0)
            ->and($rows['Zain Medical Center']['can_delete'])->toBeFalse()
            ->and($rows['SKN Health Services']['can_delete'])->toBeTrue();

        foreach ([$used, $onDeleted] as $center) {
            $this->deleteJson("/api/admin/medical-centers/{$center->id}")
                ->assertUnprocessable()
                ->assertJsonPath('code', 'medical_center_has_passports');
            expect($center->fresh()->trashed())->toBeFalse();
        }

        $this->patchJson("/api/admin/medical-centers/{$used->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.can_delete', false);
    });

    it('rejects a duplicate name, deleted centres included, whatever the case', function () {
        MedicalCenter::factory()->create(['name' => 'Old Centre'])->delete();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->postJson('/api/data-entry/medical-centers', ['name' => 'meem medical center'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'A medical centre with this name already exists.']);
        $this->postJson('/api/data-entry/medical-centers', ['name' => 'Old Centre'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->postJson('/api/data-entry/medical-centers', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });

    it('keeps the management endpoints from everyone but the operations head', function (string $role) {
        $center = MedicalCenter::query()->first();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/admin/medical-centers')->assertForbidden();
        $this->patchJson("/api/admin/medical-centers/{$center->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/admin/medical-centers/{$center->id}")->assertForbidden();
    })->with(['admin', 'superAdmin', 'dataEntry', 'accounts', 'accountsHead']);

    it('lets data entry and the operations head add a centre from the medical slip', function (string $role) {
        $user = User::factory()->{$role}()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/data-entry/medical-centers', ['name' => 'Popular Diagnostic'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Popular Diagnostic')
            ->assertJsonPath('data.is_active', true);
        expect(MedicalCenter::query()->where('name', 'Popular Diagnostic')->value('created_by'))->toBe($user->id);
    })->with(['dataEntry', 'operationHead']);

    it('keeps adding a centre from admins and accounts', function (string $role) {
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->postJson('/api/data-entry/medical-centers', ['name' => 'X'])->assertForbidden();
        expect(MedicalCenter::query()->where('name', 'X')->exists())->toBeFalse();
    })->with(['admin', 'superAdmin', 'accounts', 'accountsHead']);

    it('rejects unauthenticated requests', function () {
        $this->getJson('/api/admin/medical-centers')->assertUnauthorized();
        $this->getJson('/api/data-entry/medical-centers')->assertUnauthorized();
    });
});

describe('medical centre search', function () {
    it('returns active centres matching the search, for the dropdown', function () {
        MedicalCenter::query()->where('name', 'Zain Medical Center')->update(['is_active' => false]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/medical-centers')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Catharsis Medical Center', 'Meem Medical Center', 'SKN Health Services']);
        $this->getJson('/api/data-entry/medical-centers?q=skn')
            ->assertJsonPath('data', [['id' => MedicalCenter::where('name', 'SKN Health Services')->value('id'), 'name' => 'SKN Health Services']]);
    });

    it('is not open to accounts', function () {
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->getJson('/api/data-entry/medical-centers')->assertForbidden();
    });
});

describe('BMET dates', function () {
    /**
     * A passport waiting for BMET (calling and visa completed).
     */
    function passportAtBmet(): PassportEntry
    {
        $passport = PassportEntry::factory()->create(['passport_received_date' => '2026-01-15', 'passport_expiry_date' => '2035-01-01']);
        MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
        PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')->create();
        PassportStepRecord::factory()->for($passport)->step('visa')->on('2026-04-03')->create();

        return $passport->fresh();
    }

    it('describes the training certificate and finger dates in the workflow config', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $fields = collect($this->getJson('/api/data-entry/workflow/config')->assertOk()->json('data.steps'))
            ->firstWhere('key', 'bmet')['fields'];

        expect(array_column($fields, 'name'))->toBe(['training_certificate_date', 'finger_date', 'step_date'])
            ->and($fields[0])->toBe(['name' => 'training_certificate_date', 'label' => 'Training date', 'type' => 'date', 'max' => null, 'in_details' => true, 'required_for' => [], 'phase' => 'training', 'after' => null])
            ->and($fields[1]['label'])->toBe('Finger date')
            ->and($fields[2]['label'])->toBe('Clearance date');
    });

    it('saves both dates and shows them on the passport and its timeline', function () {
        $passport = passportAtBmet();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $id = $this->postJson("/api/data-entry/passports/{$passport->id}/steps/bmet", [
            'status' => 'in_process',
            'step_date' => '2026-04-20',
            'details' => ['training_certificate_date' => '2026-04-10', 'finger_date' => '2026-04-15'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.details', ['training_certificate_date' => '2026-04-10', 'finger_date' => '2026-04-15'])
            ->json('data.id');

        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertJsonPath('data.workflow.steps.4.key', 'bmet')
            ->assertJsonPath('data.workflow.steps.4.record.details.training_certificate_date', '2026-04-10')
            ->assertJsonPath('data.workflow.steps.4.record.details.finger_date', '2026-04-15');
        $this->getJson("/api/data-entry/passports/{$passport->id}/steps")
            ->assertJsonPath('data.bmet.0.details.finger_date', '2026-04-15');

        // Completing keeps them, and they stay optional.
        $this->patchJson("/api/data-entry/step-records/{$id}", ['status' => 'completed', 'reference_no' => 'BMET-77'])
            ->assertOk()
            ->assertJsonPath('data.details.training_certificate_date', '2026-04-10')
            ->assertJsonPath('passport.current_stage', 'flight');

        expect(PassportStepRecord::query()->where('step_key', 'bmet')->sole()->status)->toBe(StepStatus::Completed);
    });

    it('keeps both dates optional', function () {
        $passport = passportAtBmet();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/bmet", ['status' => 'in_process', 'step_date' => '2026-04-20'])
            ->assertCreated()
            ->assertJsonPath('data.details', null);
    });

    it('rejects bad or future dates', function (array $details, string $field) {
        $passport = passportAtBmet();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/bmet", ['status' => 'in_process', 'step_date' => '2026-04-20', 'details' => $details])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'training date format' => [['training_certificate_date' => '10/04/2026'], 'details.training_certificate_date'],
        'finger date format' => [['finger_date' => 'soon'], 'details.finger_date'],
        // 2 May is tomorrow in Dhaka.
        'future training date' => [['training_certificate_date' => '2026-05-02'], 'details.training_certificate_date'],
        'future finger date' => [['finger_date' => '2026-05-02'], 'details.finger_date'],
    ]);

    it('accepts today in Dhaka', function () {
        $passport = passportAtBmet();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/bmet", [
            'status' => 'in_process',
            'step_date' => '2026-05-01',
            'details' => ['finger_date' => '2026-05-01'],
        ])->assertCreated();
    });
});
