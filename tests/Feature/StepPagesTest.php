<?php

use App\Enums\ReferenceType;
use App\Enums\StepStatus;
use App\Models\Company;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Today in Dhaka is 2026-05-01 (00:30), while UTC is still on 2026-04-30.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

const RECORD_STEPS = ['calling', 'visa', 'bmet', 'flight'];

/**
 * A passport with a fit medical on 2026-04-01 (valid until 2026-07-01), or
 * the given medical state ("expired": valid until yesterday in Dhaka).
 *
 * @param  array<string, mixed>  $attributes
 */
function stepsPassport(string $medical = 'fit', array $attributes = []): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-15')->create([
        'passport_received_date' => '2026-01-15',
        'passport_expiry_date' => '2035-01-01',
        // Marked Online, so a fit passport is available for Calling.
        'entry_mode' => 'online',
        ...$attributes,
    ]);
    $factory = MedicalRecord::factory()->for($passport);

    match ($medical) {
        'fit' => $factory->on('2026-04-01')->create(),
        'expired' => $factory->validUntil('2026-04-30')->create(),
        'unfit' => $factory->unfit()->on('2026-04-01')->create(),
        'pending' => null,
    };

    return $passport->fresh();
}

/**
 * Add a record for each step, in order: completed, except the last one may
 * have another status. Dates run a day apart from $from.
 *
 * @param  list<string>  $steps
 */
function stepsRecords(PassportEntry $passport, array $steps, StepStatus $last = StepStatus::Completed, string $from = '2026-04-02'): PassportEntry
{
    foreach (array_values($steps) as $index => $step) {
        $status = $index === count($steps) - 1 ? $last : StepStatus::Completed;
        $factory = PassportStepRecord::factory()->for($passport)->step($step)->status($status)
            ->on(Carbon::parse($from)->addDays($index)->toDateString())
            // Only a completed record has its number yet.
            ->state(['reference_no' => $status === StepStatus::Completed && $step !== 'flight' ? 'REF-'.$index : null]);
        ($step === 'flight' ? $factory->flight('2026-04-30') : $factory)->create();
    }

    return $passport->fresh();
}

/**
 * The steps before $step.
 *
 * @return list<string>
 */
function stepsBefore(string $step): array
{
    return array_slice(RECORD_STEPS, 0, array_search($step, RECORD_STEPS, true));
}

/**
 * IDs in a step's tab.
 *
 * @return list<int>
 */
function stepTab(string $step, string $tab, string $query = ''): array
{
    return test()->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}&per_page=100&{$query}")->assertOk()->json('data.*.id');
}

/**
 * A valid start body for a step.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function startBody(string $step, array $overrides = []): array
{
    $body = ['step_date' => '2026-04-20'];

    if ($step === 'flight') {
        $body['details'] = ['airline' => 'Sample Air', 'flight_no' => 'SA123', 'departure_date' => '2026-04-28'];
    }

    return [...$body, ...$overrides];
}

describe('config', function () {
    it('marks every field with its phase', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $steps = collect($this->getJson('/api/data-entry/workflow/config')->assertOk()->json('data.steps'))->keyBy('key');
        $phases = fn (string $step) => collect($steps[$step]['fields'])->pluck('phase', 'name')->all();

        expect($phases('calling'))->toBe(['receive_date' => 'result', 'issue_date' => 'result', 'valid_until' => 'result'])
            ->and($phases('visa'))->toBe(['step_date' => 'start', 'issue_date' => 'result', 'valid_until' => 'result', 'reference_no' => 'result'])
            ->and($phases('bmet'))->toBe(['training_certificate_date' => 'training', 'finger_date' => 'finger', 'step_date' => 'clearance'])
            ->and($phases('flight'))->toBe(['booking_date' => 'booking', 'confirmed_date' => 'confirm'])
            ->and($steps['flight']['statuses'])->toBe([
                ['value' => 'in_process', 'label' => 'Booked'],
                ['value' => 'completed', 'label' => 'Flight confirmed'],
            ]);
    });
});

describe('tabs', function () {
    it('keeps the calling pending tab to fit medicals still valid today (Dhaka)', function () {
        $valid = stepsPassport('fit');
        // Valid until yesterday in Dhaka, although that is still today in UTC.
        stepsPassport('expired');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(stepTab('calling', 'pending'))->toBe([$valid->id]);
    });

    it('returns the row fields for the step page', function () {
        $karim = Reference::factory()->create(['type' => ReferenceType::Person, 'name' => 'Abdul Karim']);
        $rose = Company::factory()->create(['name' => 'Desert Rose Construction']);
        $passport = stepsRecords(stepsPassport('fit', ['reference_id' => $karim->id, 'company_id' => $rose->id, 'passport_number' => 'AB1234567']), ['calling', 'visa'], StepStatus::InProcess);
        PassportStepRecord::query()->where('step_key', 'visa')->update(['reference_no' => 'V-1', 'valid_until' => '2026-10-01']);
        Sanctum::actingAs(User::factory()->accounts()->create());

        $row = $this->getJson('/api/data-entry/steps/visa/passports?tab=pending')->assertOk()->json('data.0');

        expect($row)->toMatchArray([
            'id' => $passport->id,
            'passport_number' => 'AB1234567',
            'reference' => ['id' => $karim->id, 'name' => 'Abdul Karim'],
            'company' => ['id' => $rose->id, 'name' => 'Desert Rose Construction'],
            // The calling record (completed 2026-04-02).
            'previous_step_completed_on' => '2026-04-02',
        ])
            ->and($row['record'])->toMatchArray(['status' => 'in_process', 'status_label' => 'Submitted', 'step_date' => '2026-04-03', 'reference_no' => 'V-1', 'valid_until' => '2026-10-01'])
            ->and($row)->toHaveKey('passport_name');

        // Calling's previous step is the medical.
        stepsPassport();
        expect($this->getJson('/api/data-entry/steps/calling/passports')->json('data.0'))
            ->toMatchArray(['record' => null, 'previous_step_completed_on' => '2026-04-01']);
    });

    it('returns 404 for an unknown step and 422 for an unknown tab', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/steps/medical/passports')->assertNotFound();
        $this->getJson('/api/data-entry/steps/bogus/passports')->assertNotFound();
        $this->getJson('/api/data-entry/steps/visa/passports?tab=waiting')->assertUnprocessable()->assertJsonValidationErrors('tab');
    });
});

describe('search', function () {
    it('matches name, number, company and reference, partially and in any case, combined', function () {
        $rose = Company::factory()->create(['name' => 'Desert Rose Construction']);
        $gulf = Company::factory()->create(['name' => 'Gulf Star Catering']);
        $karim = Reference::factory()->create(['type' => ReferenceType::Person, 'name' => 'Abdul Karim']);
        $agency = Reference::factory()->create(['type' => ReferenceType::Agency, 'name' => 'Sea Star Overseas']);
        $roseKarim = stepsPassport('fit', ['company_id' => $rose->id, 'reference_id' => $karim->id, 'passport_number' => 'RK1111111', 'passport_name' => 'Rahim Uddin']);
        $roseAgency = stepsPassport('fit', ['company_id' => $rose->id, 'reference_id' => $agency->id, 'passport_number' => 'RA2222222']);
        $gulfKarim = stepsPassport('fit', ['company_id' => $gulf->id, 'reference_id' => $karim->id, 'passport_number' => 'GK3333333']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(stepTab('calling', 'pending', 'company=ROSE'))->toEqualCanonicalizing([$roseKarim->id, $roseAgency->id])
            ->and(stepTab('calling', 'pending', 'reference=karim'))->toEqualCanonicalizing([$roseKarim->id, $gulfKarim->id])
            ->and(stepTab('calling', 'pending', 'company=desert&reference=KARIM'))->toBe([$roseKarim->id])
            ->and(stepTab('calling', 'pending', 'search=rahim'))->toBe([$roseKarim->id])
            ->and(stepTab('calling', 'pending', 'search=ra 2222 222&company=rose'))->toBe([$roseAgency->id])
            ->and(stepTab('calling', 'pending', 'company=nowhere'))->toBe([]);
    });
});

describe('ordering', function () {
    it('orders pending by the longest wait, the other tabs newest first, ignoring sort', function () {
        // Pending BMET: visa completed on 04-11, 04-03 and 04-21.
        $middle = stepsRecords(stepsPassport(), ['calling', 'visa'], from: '2026-04-10');
        $oldest = stepsRecords(stepsPassport(), ['calling', 'visa'], from: '2026-04-02');
        $newest = stepsRecords(stepsPassport(), ['calling', 'visa'], from: '2026-04-20');
        // BMET in progress since 04-06 and 04-26; cleared on 04-07 and 04-27.
        $startedEarly = stepsRecords(stepsPassport(), ['calling', 'visa', 'bmet'], StepStatus::InProcess, '2026-04-04');
        $startedLate = stepsRecords(stepsPassport(), ['calling', 'visa', 'bmet'], StepStatus::InProcess, '2026-04-24');
        $doneEarly = stepsRecords(stepsPassport(), ['calling', 'visa', 'bmet'], from: '2026-04-05');
        $doneLate = stepsRecords(stepsPassport(), ['calling', 'visa', 'bmet'], from: '2026-04-25');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        // Pending (not started and in progress): waiting since the visa was
        // issued, or since the first milestone.
        $pending = [$oldest->id, $startedEarly->id, $middle->id, $newest->id, $startedLate->id];

        expect(stepTab('bmet', 'pending'))->toBe($pending)
            ->and(stepTab('bmet', 'pending', 'sort=created_at&direction=desc'))->toBe($pending)
            ->and(stepTab('bmet', 'cleared', 'sort=passport_name'))->toBe([$doneLate->id, $doneEarly->id]);
    });

    it('paginates', function () {
        foreach (range(1, 3) as $i) {
            stepsPassport();
        }
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/steps/calling/passports?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');
    });
});

describe('start', function () {
    it('starts the visa with its start fields, moving the passport to in process', function () {
        $passport = stepsRecords(stepsPassport(), ['calling']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", startBody('visa', [
            // Result fields and a status are not taken at the start.
            'status' => 'completed',
            'reference_no' => 'IGNORED',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_process')
            ->assertJsonPath('data.step_date', '2026-04-20')
            ->assertJsonPath('data.reference_no', null)
            ->assertJsonPath('passport.current_stage', 'visa')
            ->assertJsonPath('passport.stage_status', 'in_process')
            ->assertJsonStructure(['warnings']);

        expect(stepTab('visa', 'pending'))->toBe([$passport->id]);
    });

    it('has no start or result for BMET: it is saved with its milestones', function () {
        $passport = stepsRecords(stepsPassport(), ['calling', 'visa']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/bmet/start", ['step_date' => '2026-04-20'])->assertNotFound();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/bmet/result", ['result' => 'completed'])->assertNotFound();
    });

    it('accepts PATCH too', function () {
        $passport = stepsRecords(stepsPassport(), ['calling']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", startBody('visa'))->assertCreated();
    });

    it('validates the start date and the required start fields', function (string $step, array $body, string $field) {
        $passport = stepsRecords(stepsPassport(), stepsBefore($step));
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/{$step}/start", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(PassportStepRecord::query()->where('step_key', $step)->count())->toBe(0);
    })->with([
        'date missing' => ['visa', [], 'step_date'],
        // 2 May is tomorrow in Dhaka.
        'date in the future' => ['visa', ['step_date' => '2026-05-02'], 'step_date'],
        'bad date' => ['visa', ['step_date' => '20/04/2026'], 'step_date'],
    ]);

    it('enforces the step order', function (Closure $setUp, string $step, string $code) {
        $passport = $setUp();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->postJson("/api/data-entry/passports/{$passport->id}/steps/{$step}/start", startBody($step));

        $code === 'not_found'
            ? $response->assertNotFound()
            : $response->assertUnprocessable()->assertJsonPath('code', $code);
    })->with([
        'visa before calling is completed' => [fn () => stepsRecords(stepsPassport(), ['calling'], StepStatus::InProcess), 'visa', 'previous_step_incomplete'],
        'flight is confirmed with its own endpoint' => [fn () => stepsRecords(stepsPassport(), ['calling', 'visa', 'bmet']), 'flight', 'not_found'],
        'a step already in process' => [fn () => stepsRecords(stepsPassport(), ['calling', 'visa'], StepStatus::InProcess), 'visa', 'step_in_process'],
        'a completed step' => [fn () => stepsRecords(stepsPassport(), ['calling', 'visa']), 'visa', 'step_already_completed'],
        'calling is saved with its own update' => [fn () => stepsPassport(), 'calling', 'not_found'],
    ]);

    it('lets a rejected step be started again, keeping the history', function () {
        $passport = stepsRecords(stepsPassport(), ['calling', 'visa'], StepStatus::Rejected);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(stepTab('visa', 'rejected'))->toBe([$passport->id]);

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", startBody('visa', ['step_date' => '2026-04-25']))->assertCreated();

        expect(stepTab('visa', 'rejected'))->toBe([])
            // Applied again: back to the Visa Pending tab.
            ->and(stepTab('visa', 'pending'))->toBe([$passport->id])
            ->and(PassportStepRecord::query()->where('step_key', 'visa')->count())->toBe(2);
    });

    it('returns the warnings', function () {
        $passport = stepsRecords(stepsPassport('fit', ['passport_expiry_date' => '2026-10-01']), ['calling']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", startBody('visa'))
            ->assertCreated()
            ->assertJsonPath('warnings.*.code', ['passport_expires_soon']);
    });
});

describe('result', function () {
    it('records the result on the in-process record, keeping the start date', function () {
        $passport = stepsRecords(stepsPassport(), ['calling']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", startBody('visa', ['step_date' => '2026-04-10']))->assertCreated();

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/result", [
            'result' => 'completed',
            'issue_date' => '2026-04-25',
            'valid_until' => '2026-12-31',
            'reference_no' => 'V-998877',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.status_label', 'Visa issued')
            ->assertJsonPath('data.step_date', '2026-04-25')
            ->assertJsonPath('data.reference_no', 'V-998877')
            ->assertJsonPath('data.valid_until', '2026-12-31')
            ->assertJsonPath('data.details.issue_date', '2026-04-25')
            ->assertJsonPath('data.details.start_date', '2026-04-10')
            ->assertJsonPath('passport.current_stage', 'bmet')
            ->assertJsonPath('passport.stage_status', 'waiting');

        expect(PassportStepRecord::query()->where('step_key', 'visa')->count())->toBe(1)
            ->and(stepTab('visa', 'issued'))->toBe([$passport->id])
            ->and(stepTab('bmet', 'pending'))->toBe([$passport->id])
            ->and($passport->fresh()->stage_status_date->toDateString())->toBe('2026-04-25');
    });

    it('defaults the result date to today in Dhaka', function () {
        $passport = stepsRecords(stepsPassport(), ['calling', 'visa'], StepStatus::InProcess);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/result", ['result' => 'rejected'])
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Rejected')
            ->assertJsonPath('data.step_date', '2026-05-01');

        expect(stepTab('visa', 'rejected'))->toBe([$passport->id]);
    });

    it('validates the result and its required fields', function (string $step, array $body, string $field) {
        $passport = stepsRecords(stepsPassport(), [...stepsBefore($step), $step], StepStatus::InProcess);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/{$step}/result", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(PassportStepRecord::query()->where('step_key', $step)->sole()->status)->toBe(StepStatus::InProcess);
    })->with([
        'missing result' => ['visa', [], 'result'],
        'in process is not a result' => ['visa', ['result' => 'in_process'], 'result'],
        'visa issued without the issue date' => ['visa', ['result' => 'completed', 'valid_until' => '2026-10-20'], 'details.issue_date'],
        'visa issued without the expired date' => ['visa', ['result' => 'completed', 'issue_date' => '2026-04-20'], 'valid_until'],
        'issue date in the future' => ['visa', ['result' => 'completed', 'issue_date' => '2026-05-02', 'valid_until' => '2026-10-20'], 'details.issue_date'],
        'visa number too long' => ['visa', ['result' => 'completed', 'issue_date' => '2026-04-20', 'valid_until' => '2026-10-20', 'reference_no' => str_repeat('V', 51)], 'reference_no'],
        'future result date' => ['visa', ['result' => 'rejected', 'result_date' => '2026-05-02'], 'result_date'],
        'expired date before the issue date' => ['visa', ['result' => 'completed', 'issue_date' => '2026-04-20', 'valid_until' => '2026-04-19'], 'valid_until'],
        'expired date on the issue date' => ['visa', ['result' => 'completed', 'issue_date' => '2026-04-20', 'valid_until' => '2026-04-20'], 'valid_until'],
    ]);

    it('has no result for the flight: it is confirmed with its own endpoint', function () {
        $passport = stepsRecords(stepsPassport(), ['calling', 'visa', 'bmet']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/flight/result", ['result' => 'completed', 'result_date' => '2026-04-28'])
            ->assertNotFound();
    });

    it('refuses a result on a step that was never started or is not in process', function (Closure $setUp) {
        $passport = $setUp();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/result", ['result' => 'completed', 'reference_no' => 'V1'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_not_started');
    })->with([
        'never started' => [fn () => stepsRecords(stepsPassport(), ['calling'])],
        'already completed' => [fn () => stepsRecords(stepsPassport(), ['calling', 'visa'])],
        'rejected' => [fn () => stepsRecords(stepsPassport(), ['calling', 'visa'], StepStatus::Rejected)],
    ]);

    it('returns the warnings', function () {
        $passport = stepsRecords(stepsPassport('fit', ['passport_expiry_date' => '2026-10-01']), ['calling', 'visa'], StepStatus::InProcess);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/result", ['result' => 'completed', 'issue_date' => '2026-04-25', 'valid_until' => '2026-12-31'])
            ->assertOk()
            ->assertJsonPath('warnings.*.code', ['passport_expires_soon']);
    });
});

describe('roles', function () {
    it('lets data entry act and read', function () {
        $passport = stepsPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/steps/calling/passports')->assertOk();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", ['step_date' => '2026-04-20', 'receive_date' => '2026-04-26', 'issue_date' => '2026-04-25', 'valid_until' => '2026-10-25'])->assertCreated();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", startBody('visa', ['step_date' => '2026-04-27']))->assertCreated();
    });

    it('lets admins read the step lists (like accounts) but not act', function (string $role) {
        $passport = stepsPassport();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/data-entry/steps/calling/passports')->assertOk();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", ['step_date' => '2026-04-20', 'receive_date' => '2026-04-26', 'issue_date' => '2026-04-25', 'valid_until' => '2026-10-25'])->assertForbidden();
    })->with(['admin', 'superAdmin']);

    it('lets accounts read the lists but not act', function () {
        $passport = stepsRecords(stepsPassport(), ['calling'], StepStatus::InProcess);
        Sanctum::actingAs(User::factory()->accounts()->create());

        foreach (RECORD_STEPS as $step) {
            $this->getJson("/api/data-entry/steps/{$step}/passports?tab=pending")->assertOk();
        }
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", startBody('visa'))->assertForbidden();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", ['step_date' => '2026-04-20', 'receive_date' => '2026-04-26', 'issue_date' => '2026-04-25', 'valid_until' => '2026-10-25'])->assertForbidden();
    });

    it('rejects guests', function () {
        $passport = stepsPassport();

        $this->getJson('/api/data-entry/steps/calling/passports')->assertUnauthorized();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", ['step_date' => '2026-04-20', 'receive_date' => '2026-04-26', 'issue_date' => '2026-04-25', 'valid_until' => '2026-10-25'])->assertUnauthorized();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", startBody('visa'))->assertUnauthorized();
    });

    it('returns 404 for actions on an unknown step', function () {
        $passport = stepsPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/medical/start", startBody('calling'))->assertNotFound();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/bogus/result", ['result' => 'completed'])->assertNotFound();
    });

    it('lets data entry and the operations head act and read', function (string $role) {
        $passport = stepsPassport();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->getJson('/api/data-entry/steps/calling/passports')->assertOk();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", ['step_date' => '2026-04-20', 'receive_date' => '2026-04-26', 'issue_date' => '2026-04-25', 'valid_until' => '2026-10-25'])->assertCreated();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", startBody('visa', ['step_date' => '2026-04-27']))->assertCreated();
    })->with(['dataEntry', 'operationHead']);
});

describe('summary', function () {
    it('counts each step tab exactly as the lists do', function () {
        stepsPassport('fit');
        stepsPassport('fit');
        stepsPassport('expired');
        stepsPassport('unfit');
        stepsPassport('pending');
        stepsRecords(stepsPassport(), ['calling'], StepStatus::InProcess);
        stepsRecords(stepsPassport(), ['calling'], StepStatus::Rejected);
        stepsRecords(stepsPassport(), ['calling']);
        stepsRecords(stepsPassport(), ['calling', 'visa'], StepStatus::InProcess);
        stepsRecords(stepsPassport(), ['calling', 'visa', 'bmet']);
        stepsRecords(stepsPassport(), ['calling', 'visa', 'bmet', 'flight'], StepStatus::Rejected);
        stepsRecords(stepsPassport(), ['calling', 'visa', 'bmet', 'flight']);
        stepsRecords(stepsPassport(), ['calling'])->delete();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $tabs = $this->getJson('/api/data-entry/workflow/summary')->assertOk()->json('data.step_tabs');

        expect(array_keys($tabs))->toBe(RECORD_STEPS)
            // Pending: 2 fit, the calling date entered, and the old rejected one.
            // Permitted: calling done and no visa record yet.
            ->and($tabs['calling'])->toBe(['pending' => 4, 'permitted' => 1])
            // Issued: the confirmed flight's passport has left the tab.
            ->and($tabs['visa'])->toBe(['pending' => 1, 'issued' => 2, 'rejected' => 0])
            ->and(array_keys($tabs['bmet']))->toBe(['pending', 'cleared'])
            ->and(array_keys($tabs['flight']))->toBe(['pending', 'confirmed']);

        foreach (RECORD_STEPS as $step) {
            foreach (array_keys($tabs[$step]) as $tab) {
                $total = $this->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}&per_page=1")->json('meta.total');

                expect($tabs[$step][$tab])->toBe($total, "{$step} {$tab}");
            }
        }
    });
});
