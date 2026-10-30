<?php

use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Freeze the clock at a local time in Dhaka (UTC+6). For example
 * "2026-05-01 00:30" is 2026-04-30 18:30 UTC: Dhaka is already on the next day.
 */
function freezeDhakaTime(string $localDateTime): void
{
    Carbon::setTestNow(Carbon::parse($localDateTime, 'Asia/Dhaka')->utc());
}

/**
 * A passport received well before the frozen dates used in these tests, with
 * a long-valid passport (so no warnings fire unless a test wants them) and its
 * medical slip date entered.
 *
 * @param  array<string, mixed>  $attributes
 */
function receivedPassport(array $attributes = []): PassportEntry
{
    return PassportEntry::factory()->create([
        'passport_received_date' => '2025-01-01',
        'passport_expiry_date' => '2035-01-01',
        // A medical result needs the slip date first.
        'medical_slip_date' => '2025-01-05',
        ...$attributes,
    ]);
}

beforeEach(function () {
    // 30 minutes after midnight in Dhaka; UTC is still on 2026-04-30.
    freezeDhakaTime('2026-05-01 00:30');
});

describe('access', function () {
    it('rejects unauthenticated requests', function (string $method, string $uri) {
        $this->json($method, $uri)->assertUnauthorized();
    })->with([
        ['GET', '/api/data-entry/passports/1/medicals'],
        ['PATCH', '/api/data-entry/passports/1/medical-date'],
        ['POST', '/api/data-entry/passports/1/medical-result'],
        ['PATCH', '/api/data-entry/medicals/1'],
        ['DELETE', '/api/data-entry/medicals/1'],
        ['GET', '/api/data-entry/medical/queue'],
        ['GET', '/api/data-entry/workflow/summary'],
    ]);

    it('rejects the accounts role', function () {
        $record = MedicalRecord::factory()->create();
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->getJson("/api/data-entry/passports/{$record->passport_entry_id}/medicals")->assertForbidden();
        recordMedical($record->passport_entry_id, '2026-04-20', 'fit')->assertForbidden();
        $this->patchJson("/api/data-entry/medicals/{$record->id}", ['remarks' => 'x'])->assertForbidden();
        $this->deleteJson("/api/data-entry/medicals/{$record->id}")->assertForbidden();
        $this->getJson('/api/data-entry/medical/queue')->assertForbidden();
        // The step bar's counts are read only, so accounts may read them (All Passports).
        $this->getJson('/api/data-entry/workflow/summary')->assertOk();
    });
});

describe('recording', function () {
    it('sets valid_until to the medical date plus 3 months without overflowing month ends', function (string $now, string $medicalDate, string $validUntil) {
        freezeDhakaTime($now);
        $passport = receivedPassport(['passport_received_date' => '2025-06-01', 'passport_expiry_date' => '2040-01-01']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        recordMedical($passport->id, $medicalDate, 'fit')
            ->assertCreated()
            ->assertJsonPath('data.valid_until', $validUntil)
            ->assertJsonPath('passport.medical_status', 'fit');

        expect(MedicalRecord::sole()->valid_until->toDateString())->toBe($validUntil);
    })->with([
        '30 November' => ['2026-01-10 12:00', '2025-11-30', '2026-02-28'],
        '31 August' => ['2026-09-01 12:00', '2026-08-31', '2026-11-30'],
        '29 February (leap year)' => ['2028-03-01 12:00', '2028-02-29', '2028-05-29'],
        'mid-month' => ['2026-05-01 12:00', '2026-04-15', '2026-07-15'],
    ]);

    it('leaves valid_until empty for an unfit result', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        recordMedical($passport->id, '2026-04-20', 'unfit')
            ->assertCreated()
            ->assertJsonPath('data.valid_until', null)
            ->assertJsonPath('data.days_left', null)
            ->assertJsonPath('passport.medical_status', 'unfit');
    });

    it('takes created_by from the logged-in user and only the result from the request', function () {
        $passport = receivedPassport();
        $clerk = User::factory()->dataEntry()->create();
        $someoneElse = User::factory()->dataEntry()->create();
        Sanctum::actingAs($clerk);

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-date", ['medical_date' => '2026-04-20'])->assertOk();
        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", [
            'result' => 'fit',
            'issue_date' => '2026-04-20',
            'expiry_date' => '2026-07-20',
            'medical_date' => '2026-01-01',
            'remarks' => 'Ignored',
            'created_by' => $someoneElse->id,
            'updated_by' => $someoneElse->id,
            'valid_until' => '2030-01-01',
            'passport_entry_id' => receivedPassport()->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.recorded_by.id', $clerk->id)
            ->assertJsonPath('data.medical_date', '2026-04-20')
            ->assertJsonPath('data.valid_until', '2026-07-20')
            ->assertJsonPath('data.remarks', null);

        expect(MedicalRecord::sole())
            ->created_by->toBe($clerk->id)
            ->updated_by->toBe($clerk->id)
            ->passport_entry_id->toBe($passport->id);
        expect($passport->fresh()->current_medical_id)->toBe(MedicalRecord::sole()->id);
    });

    it('uses the Dhaka date for "today" around midnight', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        // 00:30 in Dhaka on 1 May (still 30 April in UTC): 1 May is today, not the future.
        recordMedical($passport->id, '2026-05-01', 'fit')
            ->assertCreated()
            ->assertJsonPath('data.days_left', 92);

        // 23:59 in Dhaka on 30 April: 1 May is still in the future.
        freezeDhakaTime('2026-04-30 23:59');
        recordMedical($passport->id, '2026-05-01', 'fit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_date' => 'The medical date cannot be in the future.']);
    });

    it('keeps the history and lists it newest first', function () {
        // Several medicals per passport come from older re-tests (only the operations head re-tests).
        $passport = receivedPassport();
        foreach (['2026-01-10', '2026-04-20', '2026-03-05'] as $date) {
            MedicalRecord::factory()->for($passport)->on($date)->create();
        }
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports/{$passport->id}/medicals")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.medical_date', '2026-04-20')
            ->assertJsonPath('data.1.medical_date', '2026-03-05')
            ->assertJsonPath('data.2.medical_date', '2026-01-10');
    });

    it('refuses a second result from data entry: only the operations head re-tests', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        recordMedical($passport->id, '2026-04-20', 'unfit')->assertCreated();

        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'fit', 'issue_date' => '2026-04-20', 'expiry_date' => '2026-07-20'])
            ->assertForbidden();

        expect(MedicalRecord::count())->toBe(1);
    });

    it('keeps the latest medical date current even when an older medical is entered later', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $latest = recordMedical($passport->id, '2026-04-20', 'fit')->json('data.id');
        recordMedical($passport->id, '2026-02-01', 'unfit')
            ->assertCreated()
            ->assertJsonPath('passport.current_medical_id', $latest)
            ->assertJsonPath('passport.medical_status', 'fit');

        expect($passport->fresh()->current_medical_id)->toBe($latest);
    });

    it('refuses a second result for the same medical date', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        recordMedical($passport->id, '2026-04-20', 'unfit')->assertCreated();

        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'fit', 'issue_date' => '2026-04-20', 'expiry_date' => '2026-07-20'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_date' => 'A result is already recorded for this medical date. Set a new medical date for a re-test.']);

        expect(MedicalRecord::count())->toBe(1);
    });
});

describe('validation', function () {
    it('validates the medical date', function (array $payload, string $message) {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-date", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_date' => $message]);

        expect($passport->fresh()->medical_date)->toBeNull();
    })->with([
        // 2 May is tomorrow in Dhaka.
        'future date' => [['medical_date' => '2026-05-02'], 'The medical date cannot be in the future.'],
        'wrong date format' => [['medical_date' => '20/04/2026'], 'must match the format'],
        'missing date' => [[], 'The medical date field is required.'],
    ]);

    it('needs the medical slip date before the medical date', function () {
        $passport = receivedPassport(['medical_slip_date' => null]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-date", ['medical_date' => '2026-04-20'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_date' => 'Enter the medical slip date before the medical date.']);
    });

    it('sets the medical date and changes nothing else', function () {
        $passport = receivedPassport();
        $before = DB::table('passport_entries')->where('id', $passport->id)->first();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-date", ['medical_date' => '2026-04-20', 'passport_name' => 'Changed'])
            ->assertOk()
            ->assertJsonPath('data.medical_date', '2026-04-20')
            ->assertJsonPath('data.medical_status', 'pending');

        $after = DB::table('passport_entries')->where('id', $passport->id)->first();

        expect($after->medical_date)->toBe('2026-04-20')
            ->and($after->passport_name)->toBe($before->passport_name)
            ->and($after->updated_at)->toBe($before->updated_at)
            ->and($after->updated_by)->toBe($before->updated_by);
    });

    it('locks the medical date for data entry once a result is recorded', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        recordMedical($passport->id, '2026-04-20', 'fit')->assertCreated();

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-date", ['medical_date' => '2026-04-25'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_date' => 'The medical date cannot be changed once a medical result is recorded.']);

        expect($passport->fresh()->medical_date->toDateString())->toBe('2026-04-20');
    });

    it('needs the medical date before the result', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'unfit'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_date' => 'Enter the medical date before recording the medical result.']);

        expect(MedicalRecord::count())->toBe(0);
    });

    it('validates the result', function (array $payload) {
        $passport = receivedPassport(['medical_date' => '2026-04-20']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('result');

        expect(MedicalRecord::count())->toBe(0);
    })->with([
        'bad result' => [['result' => 'maybe']],
        'missing result' => [[]],
    ]);

    it('lets data entry correct the remarks of their own record', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $id = recordMedical($passport->id, '2026-04-20', 'unfit')->json('data.id');

        $this->patchJson("/api/data-entry/medicals/{$id}", ['remarks' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remarks');
        $this->patchJson("/api/data-entry/medicals/{$id}", ['remarks' => 'Chest X-ray'])
            ->assertOk()
            ->assertJsonPath('data.remarks', 'Chest X-ray');
    });

    it('returns 404 for a deleted passport', function () {
        $passport = receivedPassport();
        $passport->delete();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        recordMedical($passport->id, '2026-04-20', 'fit')
            ->assertNotFound();
    });

    it('lets the operations head correct the remarks after recording', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $id = recordMedical($passport->id, '2026-04-20', 'unfit')->json('data.id');

        $this->patchJson("/api/data-entry/medicals/{$id}", ['remarks' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remarks');
        $this->patchJson("/api/data-entry/medicals/{$id}", ['remarks' => 'Chest X-ray'])
            ->assertOk()
            ->assertJsonPath('data.remarks', 'Chest X-ray');
    });
});

describe('warnings', function () {
    it('warns but still saves', function (array $passportAttributes, string $medicalDate, string $code) {
        $passport = receivedPassport($passportAttributes);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        recordMedical($passport->id, $medicalDate, 'fit')
            ->assertCreated()
            ->assertJsonCount(1, 'warnings')
            ->assertJsonPath('warnings.0.code', $code);

        expect(MedicalRecord::count())->toBe(1);
    })->with([
        'medical before the passport was received' => [['passport_received_date' => '2026-04-25'], '2026-04-20', 'medical_before_received'],
        'passport already expired' => [['passport_expiry_date' => '2026-04-30'], '2026-04-20', 'passport_expired'],
        'passport expires before valid_until' => [['passport_expiry_date' => '2026-06-01'], '2026-04-20', 'passport_expires_before_medical'],
    ]);

    it('returns an empty warnings array for normal dates', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        recordMedical($passport->id, '2026-04-20', 'fit')
            ->assertCreated()
            ->assertJsonPath('warnings', []);
    });
});

describe('re-tests', function () {
    it('keeps data entry from starting a re-test of fit, expiring or expired passports', function (string $validUntil) {
        $passport = receivedPassport();
        MedicalRecord::factory()->for($passport)->validUntil($validUntil)->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        recordMedical($passport->id, '2026-04-30', 'fit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('medical_date');

        expect(MedicalRecord::count())->toBe(1);
    })->with([
        'fit' => '2026-07-01',
        'expiring soon' => '2026-05-05',
        'expired' => '2026-04-01',
    ]);

    it('lets no admin re-test fit, expiring and expired passports: admins are out of passport entry', function (string $role, string $validUntil) {
        $passport = receivedPassport();
        MedicalRecord::factory()->for($passport)->validUntil($validUntil)->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        recordMedical($passport->id, '2026-04-30', 'fit')->assertForbidden();

        expect(MedicalRecord::count())->toBe(1);
    })->with(['admin', 'superAdmin'])->with(['2026-07-01', '2026-05-05', '2026-04-01']);

    it('forbids data entry from re-testing an unfit passport', function () {
        $passport = receivedPassport();
        MedicalRecord::factory()->for($passport)->unfit()->on('2026-04-01')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        // Neither a new medical date nor a result: re-tests are for the operations head.
        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-date", ['medical_date' => '2026-04-30'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_date' => 'The medical date cannot be changed once a medical result is recorded.']);
        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'fit', 'issue_date' => '2026-04-30', 'expiry_date' => '2026-07-30'])->assertForbidden();

        expect(MedicalRecord::count())->toBe(1);
    });

    it('lets no admin re-test an unfit passport', function (string $role) {
        $passport = receivedPassport();
        MedicalRecord::factory()->for($passport)->unfit()->on('2026-04-01')->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        recordMedical($passport->id, '2026-04-30', 'fit')->assertForbidden();

        expect(MedicalRecord::count())->toBe(1);
    })->with(['admin', 'superAdmin']);

    it('lets the operations head re-test fit, expiring and expired passports', function (string $role, string $validUntil) {
        $passport = receivedPassport();
        MedicalRecord::factory()->for($passport)->validUntil($validUntil)->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        recordMedical($passport->id, '2026-04-30', 'fit')
            ->assertCreated()
            ->assertJsonPath('data.valid_until', '2026-07-30')
            ->assertJsonPath('passport.medical_status', 'fit');

        expect(MedicalRecord::count())->toBe(2);
    })->with(['operationHead'])->with(['2026-07-01', '2026-05-05', '2026-04-01']);

    it('forbids data entry from recording a result on an unfit passport', function () {
        $passport = receivedPassport();
        MedicalRecord::factory()->for($passport)->unfit()->on('2026-04-01')->create();

        // Even after an admin sets the re-test date, the result is admin-only.
        Sanctum::actingAs(User::factory()->operationHead()->create());
        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-date", ['medical_date' => '2026-04-30'])->assertOk();

        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'fit', 'issue_date' => '2026-04-30', 'expiry_date' => '2026-07-30'])->assertForbidden();

        expect(MedicalRecord::count())->toBe(1);
    });

    it('lets the operations head re-test an unfit passport', function (string $role) {
        $passport = receivedPassport();
        MedicalRecord::factory()->for($passport)->unfit()->on('2026-04-01')->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        recordMedical($passport->id, '2026-04-30', 'fit')
            ->assertCreated()
            ->assertJsonPath('passport.medical_status', 'fit');

        expect(MedicalRecord::count())->toBe(2);
    })->with(['operationHead']);
});

describe('editing', function () {
    it('lets data entry edit only their own records', function () {
        $clerk = User::factory()->dataEntry()->create();
        $own = MedicalRecord::factory()->for(receivedPassport())->createdBy($clerk)->on('2026-04-01')->create();
        $others = MedicalRecord::factory()->for(receivedPassport())->on('2026-04-01')->create();
        Sanctum::actingAs($clerk);

        $this->patchJson("/api/data-entry/medicals/{$own->id}", ['remarks' => 'Corrected'])
            ->assertOk()
            ->assertJsonPath('data.remarks', 'Corrected')
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', false);
        $this->patchJson("/api/data-entry/medicals/{$others->id}", ['remarks' => 'Corrected'])
            ->assertForbidden();
    });

    it('keeps someone else\'s record from other data entry users and admins', function (string $role) {
        $record = MedicalRecord::factory()->for(receivedPassport())->on('2026-04-01')->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->patchJson("/api/data-entry/medicals/{$record->id}", ['remarks' => 'Corrected'])->assertForbidden();
        expect($record->fresh()->remarks)->not->toBe('Corrected');
    })->with(['dataEntry', 'admin', 'superAdmin']);

    it('lets the operations head edit any record, with entered dates, and moves the current record', function () {
        $passport = receivedPassport();
        $older = MedicalRecord::factory()->for($passport)->on('2026-03-01')->create();
        $newer = MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
        $head = User::factory()->operationHead()->create();
        Sanctum::actingAs($head);

        // Moving the older record past the newer one makes it current.
        $this->patchJson("/api/data-entry/medicals/{$older->id}", ['medical_date' => '2026-04-15', 'issue_date' => '2026-04-15', 'expiry_date' => '2026-07-15'])
            ->assertOk()
            ->assertJsonPath('data.issue_date', '2026-04-15')
            ->assertJsonPath('data.valid_until', '2026-07-15')
            ->assertJsonPath('passport.current_medical_id', $older->id);

        expect($older->fresh()->updated_by)->toBe($head->id);

        $this->patchJson("/api/data-entry/medicals/{$newer->id}", ['result' => 'unfit'])
            ->assertOk()
            ->assertJsonPath('data.valid_until', null);
    });
});

describe('deleting', function () {
    it('forbids data entry from deleting, even their own record', function () {
        $clerk = User::factory()->dataEntry()->create();
        $record = MedicalRecord::factory()->createdBy($clerk)->create();
        Sanctum::actingAs($clerk);

        $this->deleteJson("/api/data-entry/medicals/{$record->id}")->assertForbidden();
    });

    it('keeps deleting a medical record from admins', function (string $role) {
        $passport = receivedPassport();
        $current = MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->deleteJson("/api/data-entry/medicals/{$current->id}")->assertForbidden();

        expect($current->fresh()->trashed())->toBeFalse()
            ->and($passport->fresh()->current_medical_id)->toBe($current->id);
    })->with(['admin', 'superAdmin']);

    it('refuses the operations head too: medical records are never deleted', function () {
        $passport = receivedPassport();
        $older = MedicalRecord::factory()->for($passport)->unfit()->on('2026-03-01')->create();
        $current = MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->deleteJson("/api/data-entry/medicals/{$current->id}")->assertForbidden();
        $this->deleteJson("/api/data-entry/medicals/{$older->id}")->assertForbidden();

        expect(MedicalRecord::query()->count())->toBe(2)
            ->and($passport->fresh()->current_medical_id)->toBe($current->id);
    });
});
