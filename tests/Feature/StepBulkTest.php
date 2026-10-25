<?php

use App\Enums\StepStatus;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Today in Dhaka is 2026-05-01.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

/**
 * A permitted passport (calling done 2026-04-02) with the later records
 * given [step => status]: visa on 2026-04-03 (issued then), BMET on
 * 2026-04-04 with both milestones (or only those in $bmetDetails).
 *
 * @param  array<string, StepStatus>  $records
 * @param  array<string, string>|null  $bmetDetails
 */
function bulkPassport(string $name, array $records = [], ?array $bmetDetails = null): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create([
        'passport_name' => $name,
        'passport_expiry_date' => '2035-01-01',
        'entry_mode' => 'online',
    ]);
    MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
    PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
        ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);

    foreach ($records as $step => $status) {
        $factory = PassportStepRecord::factory()->for($passport)->step($step)->status($status);

        match ($step) {
            'visa' => $factory->on('2026-04-03')->create([
                'reference_no' => null,
                ...($status === StepStatus::Completed ? ['details' => ['issue_date' => '2026-04-03'], 'valid_until' => '2026-10-03'] : []),
            ]),
            'bmet' => $factory->on('2026-04-04')->create([
                'reference_no' => null,
                'details' => $bmetDetails ?? ['training_certificate_date' => '2026-03-20', 'finger_date' => '2026-03-25'],
            ]),
            'flight' => $factory->flight('2026-05-20', '2026-04-28')->create(),
        };
    }

    return $passport->fresh();
}

/**
 * @param  list<PassportEntry>  $passports
 * @param  array<string, mixed>  $data
 */
function bulk(string $step, string $action, array $passports, array $data = []): TestResponse
{
    return test()->postJson("/api/data-entry/steps/{$step}/bulk", [
        'action' => $action,
        'passport_ids' => array_map(fn (PassportEntry $p) => $p->id, $passports),
        'data' => $data,
    ]);
}

describe('bulk: Calling Permitted → Visa submitted', function () {
    it('starts the visa for every selected passport with one date', function () {
        $a = bulkPassport('MD RAHIM');
        $b = bulkPassport('MD KARIM');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = bulk('visa', 'start', [$a, $b], ['status' => 'in_process', 'remarks' => null, 'step_date' => '2026-04-30'])->assertOk();

        expect($response->json('updated.*.id'))->toBe([$a->id, $b->id])
            ->and($response->json('skipped'))->toBe([])
            ->and($response->json('updated.0.record_id'))->toBeInt();
        foreach ([$a, $b] as $passport) {
            expect($passport->fresh())->current_stage->toBe('visa')->stage_status->toBe('in_process');
        }
    });

    it('skips a passport that is not ready, with the reason, and saves the others', function () {
        $ready = bulkPassport('MD RAHIM');
        $started = bulkPassport('MD KARIM', ['visa' => StepStatus::InProcess]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = bulk('visa', 'start', [$ready, $started], ['status' => 'in_process', 'step_date' => '2026-04-30'])->assertOk();

        expect($response->json('updated.*.id'))->toBe([$ready->id])
            ->and($response->json('skipped.0.id'))->toBe($started->id)
            ->and($response->json('skipped.0.passport_number'))->toBe($started->passport_number)
            ->and($response->json('skipped.0.reason'))->toContain('Visa');
    });

    it('checks the date with the single action\'s rules (not in the future)', function () {
        $a = bulkPassport('MD RAHIM');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = bulk('visa', 'start', [$a], ['status' => 'in_process', 'step_date' => '2026-05-09'])->assertOk();

        expect($response->json('updated'))->toBe([])
            ->and($response->json('skipped.0.reason'))->not->toBeEmpty();
        expect($a->fresh()->current_stage)->toBe('visa')->and($a->fresh()->stage_status)->toBe('waiting');
    });
});

describe('bulk: Visa Pending → Visa issued / Rejected', function () {
    it('issues the visa with one issue date and expired date', function () {
        $a = bulkPassport('MD RAHIM', ['visa' => StepStatus::InProcess]);
        $b = bulkPassport('MD KARIM', ['visa' => StepStatus::InProcess]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = bulk('visa', 'result', [$a, $b], [
            'result' => 'completed',
            'result_date' => '2026-04-29',
            'details' => ['issue_date' => '2026-04-29'],
            'valid_until' => '2026-10-29',
        ])->assertOk();

        expect($response->json('updated.*.id'))->toBe([$a->id, $b->id]);
        $visa = PassportStepRecord::query()->where('passport_entry_id', $a->id)->where('step_key', 'visa')->sole();
        expect($visa->status)->toBe(StepStatus::Completed)
            ->and($visa->valid_until->toDateString())->toBe('2026-10-29')
            ->and($visa->detail('issue_date'))->toBe('2026-04-29')
            // The submitted date is kept, as with the single result.
            ->and($visa->detail('start_date'))->toBe('2026-04-03');
    });

    it('rejects many visas at once', function () {
        $a = bulkPassport('MD RAHIM', ['visa' => StepStatus::InProcess]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        bulk('visa', 'result', [$a], ['result' => 'rejected', 'result_date' => '2026-04-29'])
            ->assertOk()
            ->assertJsonPath('updated.0.id', $a->id);

        expect($a->fresh()->stage_status)->toBe('rejected');
    });

    it('skips a passport whose visa is not submitted', function () {
        $a = bulkPassport('MD RAHIM');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        bulk('visa', 'result', [$a], ['result' => 'rejected', 'result_date' => '2026-04-29'])
            ->assertOk()
            ->assertJsonPath('updated', [])
            ->assertJsonPath('skipped.0.reason', 'The Visa step has no record in process: start it before recording its result.');
    });
});

describe('bulk: BMET Pending', function () {
    it('marks Training done for the ones not done yet and skips the ones already done', function () {
        $fresh = bulkPassport('MD RAHIM', ['visa' => StepStatus::Completed]);
        $trained = bulkPassport('MD KARIM', ['visa' => StepStatus::Completed, 'bmet' => StepStatus::InProcess], ['training_certificate_date' => '2026-04-10']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = bulk('bmet', 'training', [$fresh, $trained], ['date' => '2026-04-30'])->assertOk();

        expect($response->json('updated.*.id'))->toBe([$fresh->id])
            ->and($response->json('updated.0.marked'))->toBe(['training'])
            ->and($response->json('skipped.0.id'))->toBe($trained->id)
            ->and($response->json('skipped.0.reason'))->toBe('Training done already on 10-04-2026.');
    });

    it('clears BMET only where training and finger are both done', function () {
        $ready = bulkPassport('MD RAHIM', ['visa' => StepStatus::Completed, 'bmet' => StepStatus::InProcess]);
        $noFinger = bulkPassport('MD KARIM', ['visa' => StepStatus::Completed, 'bmet' => StepStatus::InProcess], ['training_certificate_date' => '2026-04-10']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = bulk('bmet', 'clearance', [$ready, $noFinger], ['date' => '2026-04-30'])->assertOk();

        expect($response->json('updated.*.id'))->toBe([$ready->id])
            ->and($ready->fresh()->current_stage)->toBe('flight')
            ->and($response->json('skipped.0.id'))->toBe($noFinger->id)
            ->and($response->json('skipped.0.reason'))->toContain('Finger');
    });

    it('"All done" marks what is missing and then clears', function () {
        $fresh = bulkPassport('MD RAHIM', ['visa' => StepStatus::Completed]);
        $trained = bulkPassport('MD KARIM', ['visa' => StepStatus::Completed, 'bmet' => StepStatus::InProcess], ['training_certificate_date' => '2026-04-10']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = bulk('bmet', 'all', [$fresh, $trained], ['date' => '2026-04-30'])->assertOk();

        expect($response->json('skipped'))->toBe([])
            ->and($response->json('updated.0.marked'))->toBe(['training', 'finger', 'clearance'])
            ->and($response->json('updated.1.marked'))->toBe(['finger', 'clearance'])
            ->and($fresh->fresh()->current_stage)->toBe('flight')
            ->and($trained->fresh()->current_stage)->toBe('flight');
    });

    it('"All done" leaves a passport untouched when a part fails', function () {
        $trained = bulkPassport('MD KARIM', ['visa' => StepStatus::Completed, 'bmet' => StepStatus::InProcess], ['training_certificate_date' => '2026-04-10']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        // The clearance date cannot be before the training date.
        $response = bulk('bmet', 'all', [$trained], ['date' => '2026-04-05'])->assertOk();

        expect($response->json('updated'))->toBe([])
            ->and($response->json('skipped.0.reason'))->not->toBeEmpty();
        $bmet = PassportStepRecord::query()->where('passport_entry_id', $trained->id)->where('step_key', 'bmet')->sole();
        expect($bmet->details)->toBe(['training_certificate_date' => '2026-04-10']);
    });
});

describe('bulk: Flight Pending', function () {
    it('sets one flight date for every selected passport, keeping the previous date for undo', function () {
        $booked = bulkPassport('MD RAHIM', ['visa' => StepStatus::Completed, 'bmet' => StepStatus::Completed, 'flight' => StepStatus::InProcess]);
        $new = bulkPassport('MD KARIM', ['visa' => StepStatus::Completed, 'bmet' => StepStatus::Completed]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = bulk('flight', 'booking', [$booked, $new], ['booking_date' => '2026-06-01'])->assertOk();

        expect($response->json('updated.*.id'))->toBe([$booked->id, $new->id])
            ->and($response->json('updated.0.previous_booking_date'))->toBe('2026-05-20')
            ->and($response->json('updated.1.previous_booking_date'))->toBeNull();
    });

    it('confirms only the flights that are ready', function () {
        $ready = bulkPassport('MD RAHIM', ['visa' => StepStatus::Completed, 'bmet' => StepStatus::Completed, 'flight' => StepStatus::InProcess]);
        $noBooking = bulkPassport('MD KARIM', ['visa' => StepStatus::Completed, 'bmet' => StepStatus::Completed]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = bulk('flight', 'confirm', [$ready, $noBooking])->assertOk();

        expect($response->json('updated.*.id'))->toBe([$ready->id])
            ->and($ready->fresh()->current_stage)->toBe('completed')
            ->and($response->json('skipped.0.id'))->toBe($noBooking->id)
            ->and($response->json('skipped.0.reason'))->not->toBeEmpty();
    });
});

describe('bulk: rules', function () {
    it('refuses an action the step does not have, an empty or too large selection', function () {
        $a = bulkPassport('MD RAHIM');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        bulk('visa', 'confirm', [$a])->assertUnprocessable()->assertJsonValidationErrors('action');
        bulk('bmet', 'start', [$a])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->postJson('/api/data-entry/steps/visa/bulk', ['action' => 'start', 'passport_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('passport_ids');
        $this->postJson('/api/data-entry/steps/visa/bulk', ['action' => 'start', 'passport_ids' => range(1, 101)])
            ->assertUnprocessable()->assertJsonValidationErrors('passport_ids');
    });

    it('lets only data entry use it, not admins, accounts or guests', function (string $role, int $status) {
        $a = bulkPassport('MD RAHIM');
        Sanctum::actingAs(User::factory()->{$role}()->create());

        bulk('visa', 'start', [$a], ['status' => 'in_process', 'step_date' => '2026-04-30'])->assertStatus($status);
    })->with([['dataEntry', 200], ['admin', 403], ['superAdmin', 403], ['accounts', 403]]);

    it('needs a login', function () {
        $a = bulkPassport('MD RAHIM');

        bulk('visa', 'start', [$a])->assertUnauthorized();
    });

    it('lets data entry and the operations head use it, but not accounts or guests', function (string $role, int $status) {
        $a = bulkPassport('MD RAHIM');
        Sanctum::actingAs(User::factory()->{$role}()->create());

        bulk('visa', 'start', [$a], ['status' => 'in_process', 'step_date' => '2026-04-30'])->assertStatus($status);
    })->with([['dataEntry', 200], ['operationHead', 200], ['admin', 403], ['superAdmin', 403], ['accounts', 403]]);
});
