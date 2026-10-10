<?php

use App\Models\Company;
use App\Models\MedicalCenter;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\Reference;
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

/**
 * IDs in the passport list for the given query string.
 *
 * @return list<int>
 */
function slipListIds(string $query = ''): array
{
    return test()->getJson("/api/data-entry/passports?per_page=100&{$query}")->assertOk()->json('data.*.id');
}

/**
 * IDs in the medical queue.
 *
 * @return list<int>
 */
function slipQueueIds(): array
{
    return test()->getJson('/api/data-entry/medical/queue?per_page=100')->assertOk()->json('data.*.id');
}

function meem(): MedicalCenter
{
    return MedicalCenter::query()->where('name', 'Meem Medical Center')->sole();
}

describe('medical pending only after the slip date', function () {
    it('follows a passport from creation, through the slip date, to its result', function () {
        $clerk = User::factory()->dataEntry()->create();
        Sanctum::actingAs($clerk);

        // A new passport: medical not started, at the Passport stage, not pending.
        $id = $this->postJson('/api/data-entry/passports', [
            'passport_name' => 'Md Karim Ali',
            'passport_number' => 'AB1234567',
            'country_code' => 'BD',
            'date_of_birth' => '1995-04-12',
            'reference_id' => Reference::factory()->create()->id,
            'company_id' => Company::factory()->create()->id,
            'passport_received_date' => '2026-04-10',
            'passport_expiry_date' => '2031-04-10',
        ])
            ->assertCreated()
            ->assertJsonPath('data.medical_status', 'not_started')
            ->assertJsonPath('data.current_stage', 'passport')
            ->assertJsonPath('data.stage_status', 'not_started')
            ->assertJsonPath('data.workflow.current_step', 'passport')
            ->assertJsonPath('data.medical_slip', ['date' => null, 'no' => null, 'medical_center' => null])
            ->json('data.id');

        expect(slipListIds())->toBe([$id])
            ->and(slipListIds('stage=passport'))->toBe([$id])
            ->and(slipListIds('medical_status=not_started'))->toBe([$id])
            ->and(slipListIds('medical_status=pending'))->toBe([])
            ->and(slipQueueIds())->toBe([]);

        // Saving the slip date moves it into Medical Pending.
        $this->patchJson("/api/data-entry/passports/{$id}/medical-slip", [
            'medical_slip_date' => '2026-04-15',
            'medical_slip_no' => 'MG-2026-0042',
            'medical_center_id' => meem()->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.medical_status', 'pending')
            ->assertJsonPath('data.current_stage', 'medical')
            ->assertJsonPath('data.stage_status', 'pending')
            ->assertJsonPath('data.medical_slip', [
                'date' => '2026-04-15',
                'no' => 'MG-2026-0042',
                'medical_center' => ['id' => meem()->id, 'name' => 'Meem Medical Center'],
            ]);

        expect(slipListIds('medical_status=pending'))->toBe([$id])
            ->and(slipListIds('stage=medical&stage_status=pending'))->toBe([$id])
            ->and(slipListIds('medical_status=not_started'))->toBe([])
            ->and(slipQueueIds())->toBe([$id])
            ->and(PassportEntry::find($id)->stage_status_date->toDateString())->toBe('2026-04-15');

        // Recording the result takes it out of pending; the slip stays on the passport.
        $this->postJson("/api/data-entry/passports/{$id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'fit'])
            ->assertCreated()
            ->assertJsonPath('passport.medical_status', 'fit');

        expect(slipListIds('medical_status=pending'))->toBe([])
            ->and(slipQueueIds())->toBe([]);

        $this->getJson("/api/data-entry/passports/{$id}")
            ->assertJsonPath('data.current_stage', 'calling')
            ->assertJsonPath('data.medical_slip.no', 'MG-2026-0042')
            ->assertJsonPath('data.current_medical.medical_date', '2026-04-20');
    });

    it('refuses a medical result without a slip date', function () {
        $passport = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'fit'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_slip_date' => 'Enter the medical slip date before recording the medical result.']);

        expect(MedicalRecord::count())->toBe(0)
            ->and($passport->fresh()->current_stage)->toBe('passport');
    });

    it('allows clearing the slip date only before a result is recorded', function () {
        $passport = PassportEntry::factory()->withMedicalSlip('2026-04-15')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => null])
            ->assertOk()
            ->assertJsonPath('data.medical_status', 'not_started')
            ->assertJsonPath('data.current_stage', 'passport');

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => '2026-04-16'])->assertOk();
        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'unfit'])->assertCreated();

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_slip_date' => 'The medical slip date cannot be cleared once a medical result is recorded.']);

        // Changing the date (not clearing it) is still fine.
        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => '2026-04-17'])
            ->assertOk()
            ->assertJsonPath('data.medical_slip.date', '2026-04-17')
            ->assertJsonPath('data.medical_status', 'unfit');
    });

    it('changes nothing else on the passport', function () {
        $creator = User::factory()->dataEntry()->create();
        $passport = PassportEntry::factory()->createdBy($creator)->create(['passport_name' => 'Old Name']);
        $before = DB::table('passport_entries')->where('id', $passport->id)->first();
        Sanctum::actingAs(User::factory()->admin()->create());

        Carbon::setTestNow(Carbon::parse('2026-05-01 10:00', 'Asia/Dhaka')->utc());
        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", [
            'medical_slip_date' => '2026-04-15',
            'passport_name' => 'New Name',
            'updated_by' => 999,
        ])->assertOk();

        $after = DB::table('passport_entries')->where('id', $passport->id)->first();

        expect($after->passport_name)->toBe('OLD NAME')
            ->and($after->updated_by)->toBe($before->updated_by)
            ->and($after->updated_at)->toBe($before->updated_at)
            ->and($after->medical_slip_date)->toBe('2026-04-15');
    });

    it('validates the slip', function (array $payload, string $field) {
        MedicalCenter::query()->where('name', 'Zain Medical Center')->update(['is_active' => false]);
        $passport = PassportEntry::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $payload = array_map(fn ($value) => $value === '__zain' ? MedicalCenter::where('name', 'Zain Medical Center')->value('id') : $value, $payload);

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'date missing' => [['medical_slip_no' => 'MG-1'], 'medical_slip_date'],
        'bad date' => [['medical_slip_date' => '15-04-2026'], 'medical_slip_date'],
        'slip number too long' => [['medical_slip_date' => '2026-04-15', 'medical_slip_no' => str_repeat('9', 51)], 'medical_slip_no'],
        'inactive centre' => [['medical_slip_date' => '2026-04-15', 'medical_center_id' => '__zain'], 'medical_center_id'],
        'unknown centre' => [['medical_slip_date' => '2026-04-15', 'medical_center_id' => 999999], 'medical_center_id'],
    ]);

    it('still accepts the passport\'s current centre after it is deactivated', function () {
        $center = meem();
        $passport = PassportEntry::factory()->withMedicalSlip()->create(['medical_center_id' => $center->id]);
        $center->is_active = false;
        $center->save();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => '2026-04-15', 'medical_center_id' => $center->id])
            ->assertOk()
            ->assertJsonPath('data.medical_slip.medical_center.name', 'Meem Medical Center');
    });

    it('lets data entry, admin and super admin save the slip, and nobody else', function () {
        $passport = PassportEntry::factory()->create();

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => '2026-04-15'])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => '2026-04-15'])->assertForbidden();

        foreach (['dataEntry', 'admin', 'superAdmin'] as $role) {
            Sanctum::actingAs(User::factory()->{$role}()->create());
            $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => '2026-04-15'])->assertOk();
        }
    });

    it('no longer takes slip fields on the medical result', function () {
        $passport = PassportEntry::factory()->withMedicalSlip()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", [
            'medical_date' => '2026-04-20',
            'result' => 'fit',
            'slip_no' => 'IGNORED',
            'medical_center_id' => meem()->id,
        ])->assertCreated()->assertJsonMissingPath('data.slip_no');

        expect($passport->fresh()->medical_center_id)->toBeNull();
    });
});

describe('counts', function () {
    it('counts not_started under the Passport stage and pending under Medical', function () {
        PassportEntry::factory()->count(2)->create();
        PassportEntry::factory()->withMedicalSlip()->count(3)->create();
        $fit = PassportEntry::factory()->withMedicalSlip()->create();
        MedicalRecord::factory()->for($fit)->on('2026-04-20')->create();
        $unfit = PassportEntry::factory()->withMedicalSlip()->create();
        MedicalRecord::factory()->for($unfit)->unfit()->on('2026-04-20')->create();
        PassportEntry::factory()->create()->delete();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->getJson('/api/data-entry/workflow/summary')->assertOk();

        expect($response->json('data.step2'))->toMatchArray(['not_started' => 2, 'pending' => 3, 'fit' => 1, 'unfit' => 1])
            ->and($response->json('data.stages.passport'))->toBe(['total' => 2, 'not_started' => 2])
            ->and($response->json('data.stages.medical'))->toBe(['total' => 4, 'pending' => 3, 'unfit' => 1])
            ->and($response->json('data.stages.calling.waiting'))->toBe(1)
            ->and($response->json('data.step1.total_all'))->toBe(7)
            // The default Passport list: every non-unfit passport, each with its status.
            ->and(collect($this->getJson('/api/data-entry/passports?per_page=100')->json('data.*.medical_status'))->countBy()->all())
            ->toBe(['fit' => 1, 'pending' => 3, 'not_started' => 2]);
    });

    it('counts pending and not_started separately in the company report', function () {
        $company = Company::factory()->create();
        PassportEntry::factory()->count(2)->create(['company_id' => $company->id]);
        PassportEntry::factory()->withMedicalSlip()->create(['company_id' => $company->id]);
        Sanctum::actingAs(User::factory()->accounts()->create());

        expect($this->getJson('/api/reports/companies')->json('data.0'))->toMatchArray([
            'total' => 3, 'medical_pending' => 1, 'not_started' => 2, 'medical_fit' => 0, 'in_process' => 3,
        ]);
    });

    it('loads the medical centres of a passport list without extra queries per passport', function () {
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
            ->for(PassportEntry::factory()->withMedicalSlip()->state(['medical_center_id' => $centers[$i % $centers->count()]]))
            ->on('2026-04-20')
            ->create();

        foreach (range(1, 2) as $i) {
            $create($i);
        }
        $few = $countQueries();

        foreach (range(3, 9) as $i) {
            $create($i);
        }

        // count + page + country + reference + company + company country
        // + medical centre + current medical + its creator + step records.
        expect($countQueries())->toBe($few)->toBe(10);
    });
});

describe('BMET record on a slipped passport', function () {
    it('keeps the later steps working with the slip on the passport', function () {
        $passport = PassportEntry::factory()->withMedicalSlip()->create(['passport_expiry_date' => '2035-01-01']);
        MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
        PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertJsonPath('data.current_stage', 'visa')
            ->assertJsonPath('data.workflow.steps.1.state', 'passed');
    });
});
