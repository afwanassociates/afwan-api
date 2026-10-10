<?php

use App\Enums\StepStatus;
use App\Models\MedicalCenter;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    it('lets admin and super admin list, create, rename, deactivate and delete centres', function (string $role) {
        $admin = User::factory()->{$role}()->create();
        Sanctum::actingAs($admin);

        $id = $this->postJson('/api/admin/medical-centers', ['name' => '  Popular   Diagnostic  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Popular Diagnostic')
            ->assertJsonPath('data.is_active', true)
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
    })->with(['admin', 'superAdmin']);

    it('rejects a duplicate name, deleted centres included, whatever the case', function () {
        MedicalCenter::factory()->create(['name' => 'Old Centre'])->delete();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/medical-centers', ['name' => 'meem medical center'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'A medical centre with this name already exists.']);
        $this->postJson('/api/admin/medical-centers', ['name' => 'Old Centre'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/medical-centers', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });

    it('keeps the admin endpoints from data entry and accounts', function (string $role) {
        $center = MedicalCenter::query()->first();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/admin/medical-centers')->assertForbidden();
        $this->postJson('/api/admin/medical-centers', ['name' => 'X'])->assertForbidden();
        $this->patchJson("/api/admin/medical-centers/{$center->id}", ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/admin/medical-centers/{$center->id}")->assertForbidden();
    })->with(['dataEntry', 'accounts']);

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

describe('medical slip and centre', function () {
    it('saves the centre and slip and returns them with the record and the passport', function () {
        $passport = PassportEntry::factory()->create(['passport_received_date' => '2026-01-15', 'passport_expiry_date' => '2035-01-01']);
        $center = MedicalCenter::query()->where('name', 'Meem Medical Center')->sole();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        // The slip date may be after the medical date: there is no ordering rule.
        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", [
            'medical_date' => '2026-04-20',
            'result' => 'fit',
            'medical_center_id' => $center->id,
            'slip_no' => 'MG-2026-0042',
            'slip_date' => '2026-04-25',
        ])
            ->assertCreated()
            ->assertJsonPath('data.medical_center', ['id' => $center->id, 'name' => 'Meem Medical Center'])
            ->assertJsonPath('data.slip_no', 'MG-2026-0042')
            ->assertJsonPath('data.slip_date', '2026-04-25')
            ->assertJsonPath('data.valid_until', '2026-07-20');

        expect(MedicalRecord::sole())
            ->medical_center_id->toBe($center->id)
            ->slip_no->toBe('MG-2026-0042');

        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertJsonPath('data.current_medical.medical_center.name', 'Meem Medical Center')
            ->assertJsonPath('data.current_medical.slip_date', '2026-04-25');
        $this->getJson("/api/data-entry/passports/{$passport->id}/medicals")
            ->assertJsonPath('data.0.slip_no', 'MG-2026-0042');
    });

    it('keeps the centre and slip optional', function () {
        $passport = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'unfit'])
            ->assertCreated()
            ->assertJsonPath('data.medical_center', null)
            ->assertJsonPath('data.slip_no', null)
            ->assertJsonPath('data.slip_date', null);
    });

    it('rejects an inactive or unknown centre and a bad slip', function (array $payload, string $field) {
        MedicalCenter::query()->where('name', 'Zain Medical Center')->update(['is_active' => false]);
        $passport = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $payload = array_map(fn ($value) => $value === '__zain' ? MedicalCenter::where('name', 'Zain Medical Center')->value('id') : $value, $payload);

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'fit', ...$payload])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'inactive centre' => [['medical_center_id' => '__zain'], 'medical_center_id'],
        'unknown centre' => [['medical_center_id' => 999999], 'medical_center_id'],
        'slip number too long' => [['slip_no' => str_repeat('9', 51)], 'slip_no'],
        'bad slip date' => [['slip_date' => '25-04-2026'], 'slip_date'],
    ]);

    it('still accepts a record\'s current centre after it is deactivated', function () {
        $center = MedicalCenter::query()->where('name', 'SKN Health Services')->sole();
        $passport = PassportEntry::factory()->create();
        $record = MedicalRecord::factory()->for($passport)->on('2026-04-20')->create(['medical_center_id' => $center->id]);
        $center->is_active = false;
        $center->save();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/data-entry/medicals/{$record->id}", ['medical_center_id' => $center->id, 'slip_no' => 'MG-1'])
            ->assertOk()
            ->assertJsonPath('data.medical_center.name', 'SKN Health Services')
            ->assertJsonPath('data.slip_no', 'MG-1');
    });

    it('loads the centres of a passport list without extra queries per passport', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $centers = MedicalCenter::query()->pluck('id');

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/data-entry/passports?medical_status=all&per_page=100')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $create = fn (int $i) => MedicalRecord::factory()
            ->for(PassportEntry::factory())
            ->on('2026-04-20')
            ->create(['medical_center_id' => $centers[$i % $centers->count()]]);

        foreach (range(1, 2) as $i) {
            $create($i);
        }
        $few = $countQueries();

        foreach (range(3, 9) as $i) {
            $create($i);
        }

        // count + page + country + reference + company + company country
        // + current medical + its creator + its centre + step records.
        expect($countQueries())->toBe($few)->toBe(10);
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

        expect(array_column($fields, 'name'))->toBe(['reference_no', 'step_date', 'training_certificate_date', 'finger_date'])
            ->and($fields[2])->toBe(['name' => 'training_certificate_date', 'label' => 'Training certificate date', 'type' => 'date', 'max' => null, 'in_details' => true, 'required_for' => []])
            ->and($fields[3]['label'])->toBe('Finger date');
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
