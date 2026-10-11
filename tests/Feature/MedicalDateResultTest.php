<?php

use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // 00:30 in Dhaka on 1 May; still 30 April in UTC.
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

describe('access', function () {
    it('lets data entry, admin and super admin set the date and record the result', function (string $role) {
        $passport = PassportEntry::factory()->withMedicalSlip()->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-date", ['medical_date' => '2026-04-20'])->assertOk();
        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'fit'])->assertCreated();
    })->with(['dataEntry', 'admin', 'superAdmin']);

    it('rejects accounts and guests', function () {
        $passport = PassportEntry::factory()->withMedicalSlip()->create(['medical_date' => '2026-04-20']);

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-date", ['medical_date' => '2026-04-20'])->assertUnauthorized();
        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'fit'])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-date", ['medical_date' => '2026-04-20'])->assertForbidden();
        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'fit'])->assertForbidden();

        expect(MedicalRecord::count())->toBe(0);
    });

    it('no longer has the old record-medical endpoint', function () {
        $passport = PassportEntry::factory()->withMedicalSlip()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'fit'])
            ->assertMethodNotAllowed();
    });
});

describe('status flow', function () {
    it('moves a fit result through fit, expiring soon and expired as the days pass', function () {
        $passport = PassportEntry::factory()->withMedicalSlip('2026-04-15')->create(['passport_expiry_date' => '2035-01-01']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-date", ['medical_date' => '2026-04-20'])
            ->assertOk()
            ->assertJsonPath('data.medical_status', 'pending');

        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'fit'])
            ->assertCreated()
            // medical date + config('medical.validity_months') (3).
            ->assertJsonPath('data.valid_until', '2026-07-20')
            ->assertJsonPath('passport.medical_status', 'fit')
            ->assertJsonPath('passport.current_stage', 'calling');

        $status = fn () => $this->getJson("/api/data-entry/passports/{$passport->id}")->json('data.medical_status');

        expect($passport->fresh()->current_medical_id)->toBe(MedicalRecord::sole()->id);

        // 15 days left: still fit; 14 (config expiring_soon_days): expiring soon.
        Carbon::setTestNow(Carbon::parse('2026-07-05 00:30', 'Asia/Dhaka')->utc());
        expect($status())->toBe('fit');
        Carbon::setTestNow(Carbon::parse('2026-07-06 00:30', 'Asia/Dhaka')->utc());
        expect($status())->toBe('expiring_soon');
        Carbon::setTestNow(Carbon::parse('2026-07-20 00:30', 'Asia/Dhaka')->utc());
        expect($status())->toBe('expiring_soon');
        Carbon::setTestNow(Carbon::parse('2026-07-21 00:30', 'Asia/Dhaka')->utc());
        expect($status())->toBe('expired');
    });

    it('leaves an unfit passport out of the default lists', function () {
        $passport = PassportEntry::factory()->withMedicalSlip()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        recordMedical($passport->id, '2026-04-20', 'unfit')
            ->assertCreated()
            ->assertJsonPath('data.valid_until', null)
            ->assertJsonPath('passport.medical_status', 'unfit');

        expect($this->getJson('/api/data-entry/passports')->json('data.*.id'))->toBe([])
            ->and($this->getJson('/api/data-entry/passports?view=overview')->json('data.*.id'))->toBe([])
            ->and($this->getJson('/api/data-entry/passports?medical_status=unfit')->json('data.*.id'))->toBe([$passport->id])
            ->and($this->getJson('/api/data-entry/passports?mode=medical&medical_status=unfit')->json('data.*.id'))->toBe([$passport->id]);
    });

    it('keeps the passport\'s medical date in step when an admin corrects the current result', function () {
        $passport = PassportEntry::factory()->withMedicalSlip()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $id = recordMedical($passport->id, '2026-04-20', 'fit')->json('data.id');

        $this->patchJson("/api/data-entry/medicals/{$id}", ['medical_date' => '2026-04-18'])
            ->assertOk()
            ->assertJsonPath('data.valid_until', '2026-07-18');

        expect($passport->fresh()->medical_date->toDateString())->toBe('2026-04-18');
    });
});

describe('medical list', function () {
    it('includes the medical date, empty until entered', function () {
        $dated = PassportEntry::factory()->withMedicalSlip('2026-04-01')->create(['medical_date' => '2026-04-20']);
        $undated = PassportEntry::factory()->withMedicalSlip('2026-04-02')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $rows = collect($this->getJson('/api/data-entry/passports?mode=medical&medical_status=pending')->assertOk()->json('data'))->keyBy('id');

        expect($rows[$dated->id]['medical_date'])->toBe('2026-04-20')
            ->and($rows[$undated->id]['medical_date'])->toBeNull()
            ->and($rows[$dated->id])->toHaveKeys(['reference', 'medical_center'])
            ->and($rows[$dated->id])->not->toHaveKey('waiting_days');
    });
});
