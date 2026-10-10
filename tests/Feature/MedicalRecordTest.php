<?php

use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
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
        ['POST', '/api/data-entry/passports/1/medicals'],
        ['PATCH', '/api/data-entry/medicals/1'],
        ['DELETE', '/api/data-entry/medicals/1'],
        ['GET', '/api/data-entry/medical/queue'],
        ['GET', '/api/data-entry/workflow/summary'],
    ]);

    it('rejects the accounts role', function () {
        $record = MedicalRecord::factory()->create();
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->getJson("/api/data-entry/passports/{$record->passport_entry_id}/medicals")->assertForbidden();
        $this->postJson("/api/data-entry/passports/{$record->passport_entry_id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'fit'])->assertForbidden();
        $this->patchJson("/api/data-entry/medicals/{$record->id}", ['remarks' => 'x'])->assertForbidden();
        $this->deleteJson("/api/data-entry/medicals/{$record->id}")->assertForbidden();
        $this->getJson('/api/data-entry/medical/queue')->assertForbidden();
        $this->getJson('/api/data-entry/workflow/summary')->assertForbidden();
    });
});

describe('recording', function () {
    it('sets valid_until to the medical date plus 3 months without overflowing month ends', function (string $now, string $medicalDate, string $validUntil) {
        freezeDhakaTime($now);
        $passport = receivedPassport(['passport_received_date' => '2025-06-01', 'passport_expiry_date' => '2040-01-01']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => $medicalDate, 'result' => 'fit'])
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

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'unfit'])
            ->assertCreated()
            ->assertJsonPath('data.valid_until', null)
            ->assertJsonPath('data.days_left', null)
            ->assertJsonPath('passport.medical_status', 'unfit');
    });

    it('takes created_by from the logged-in user and ignores derived fields in the request', function () {
        $passport = receivedPassport();
        $clerk = User::factory()->dataEntry()->create();
        $someoneElse = User::factory()->admin()->create();
        Sanctum::actingAs($clerk);

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", [
            'medical_date' => '2026-04-20',
            'result' => 'fit',
            'created_by' => $someoneElse->id,
            'updated_by' => $someoneElse->id,
            'valid_until' => '2030-01-01',
            'passport_entry_id' => receivedPassport()->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.recorded_by.id', $clerk->id)
            ->assertJsonPath('data.valid_until', '2026-07-20');

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
        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-05-01', 'result' => 'fit'])
            ->assertCreated()
            ->assertJsonPath('data.days_left', 92);

        // 23:59 in Dhaka on 30 April: 1 May is still in the future.
        freezeDhakaTime('2026-04-30 23:59');
        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-05-01', 'result' => 'fit'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['medical_date' => 'The medical date cannot be in the future.']);
    });

    it('keeps the history and lists it newest first', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        foreach (['2026-01-10', '2026-04-20', '2026-03-05'] as $date) {
            $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => $date, 'result' => 'fit'])->assertCreated();
        }

        $this->getJson("/api/data-entry/passports/{$passport->id}/medicals")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.medical_date', '2026-04-20')
            ->assertJsonPath('data.1.medical_date', '2026-03-05')
            ->assertJsonPath('data.2.medical_date', '2026-01-10');
    });

    it('keeps the latest medical date current even when an older medical is entered later', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $latest = $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'fit'])->json('data.id');
        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-02-01', 'result' => 'unfit'])
            ->assertCreated()
            ->assertJsonPath('passport.current_medical_id', $latest)
            ->assertJsonPath('passport.medical_status', 'fit');

        expect($passport->fresh()->current_medical_id)->toBe($latest);
    });

    it('breaks a tie on the same medical date by the newest record', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'unfit'])->assertCreated();
        $second = $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'fit'])->json('data.id');

        expect($passport->fresh()->current_medical_id)->toBe($second);
    });
});

describe('validation', function () {
    it('rejects invalid input', function (array $payload, string $field) {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", [
            'medical_date' => '2026-04-20',
            'result' => 'fit',
            ...$payload,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(MedicalRecord::count())->toBe(0);
    })->with([
        'future date' => [['medical_date' => '2026-05-02'], 'medical_date'],
        'older than 1 year' => [['medical_date' => '2025-04-30'], 'medical_date'],
        'wrong date format' => [['medical_date' => '20/04/2026'], 'medical_date'],
        'missing date' => [['medical_date' => null], 'medical_date'],
        'bad result' => [['result' => 'maybe'], 'result'],
        'missing result' => [['result' => null], 'result'],
        'remarks too long' => [['remarks' => str_repeat('a', 1001)], 'remarks'],
    ]);

    it('accepts a medical exactly 1 year old and 1000 characters of remarks', function () {
        $passport = receivedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", [
            'medical_date' => '2025-05-01',
            'result' => 'fit',
            'remarks' => str_repeat('a', 1000),
        ])->assertCreated();
    });

    it('returns 404 for a deleted passport', function () {
        $passport = receivedPassport();
        $passport->delete();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'fit'])
            ->assertNotFound();
    });
});

describe('warnings', function () {
    it('warns but still saves', function (array $passportAttributes, string $medicalDate, string $code) {
        $passport = receivedPassport($passportAttributes);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => $medicalDate, 'result' => 'fit'])
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

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-20', 'result' => 'fit'])
            ->assertCreated()
            ->assertJsonPath('warnings', []);
    });
});

describe('re-tests', function () {
    it('lets data entry re-test pending, fit, expiring and expired passports', function (string $validUntil) {
        $passport = receivedPassport();
        MedicalRecord::factory()->for($passport)->validUntil($validUntil)->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-30', 'result' => 'fit'])
            ->assertCreated();
    })->with([
        'fit' => '2026-07-01',
        'expiring soon' => '2026-05-05',
        'expired' => '2026-04-01',
    ]);

    it('forbids data entry from re-testing an unfit passport', function () {
        $passport = receivedPassport();
        MedicalRecord::factory()->for($passport)->unfit()->on('2026-04-01')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-30', 'result' => 'fit'])
            ->assertForbidden();

        expect(MedicalRecord::count())->toBe(1);
    });

    it('lets admin and super admin re-test an unfit passport', function (string $role) {
        $passport = receivedPassport();
        MedicalRecord::factory()->for($passport)->unfit()->on('2026-04-01')->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-30', 'result' => 'fit'])
            ->assertCreated()
            ->assertJsonPath('passport.medical_status', 'fit');

        expect(MedicalRecord::count())->toBe(2);
    })->with(['admin', 'superAdmin']);
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

    it('lets admin edit any record, re-deriving valid_until and the current record', function () {
        $passport = receivedPassport();
        $older = MedicalRecord::factory()->for($passport)->on('2026-03-01')->create();
        $newer = MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        // Moving the older record past the newer one makes it current.
        $this->patchJson("/api/data-entry/medicals/{$older->id}", ['medical_date' => '2026-04-15'])
            ->assertOk()
            ->assertJsonPath('data.valid_until', '2026-07-15')
            ->assertJsonPath('passport.current_medical_id', $older->id);

        expect($older->fresh()->updated_by)->toBe($admin->id);

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

    it('moves the current record back to the previous one, then to pending', function (string $role) {
        $passport = receivedPassport();
        $previous = MedicalRecord::factory()->for($passport)->unfit()->on('2026-03-01')->create();
        $current = MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
        $user = User::factory()->{$role}()->create();
        Sanctum::actingAs($user);

        expect($passport->fresh()->current_medical_id)->toBe($current->id);

        $this->deleteJson("/api/data-entry/medicals/{$current->id}")->assertNoContent();

        expect($passport->fresh()->current_medical_id)->toBe($previous->id);
        expect(MedicalRecord::withTrashed()->find($current->id))
            ->trashed()->toBeTrue()
            ->deleted_by->toBe($user->id);

        $this->deleteJson("/api/data-entry/medicals/{$previous->id}")->assertNoContent();

        expect($passport->fresh()->current_medical_id)->toBeNull();
        $this->getJson("/api/data-entry/passports/{$passport->id}")->assertJsonPath('data.medical_status', 'pending');
    })->with(['admin', 'superAdmin']);

    it('keeps the current record when an older one is deleted', function () {
        $passport = receivedPassport();
        $older = MedicalRecord::factory()->for($passport)->on('2026-03-01')->create();
        $current = MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/data-entry/medicals/{$older->id}")->assertNoContent();

        expect($passport->fresh()->current_medical_id)->toBe($current->id);
        $this->getJson("/api/data-entry/passports/{$passport->id}/medicals")->assertJsonCount(1, 'data');
    });
});
