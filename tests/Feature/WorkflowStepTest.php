<?php

use App\Enums\StepStatus;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use App\Services\WorkflowService;
use Database\Seeders\WorkflowDataSeeder;
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
 * A passport with a fit medical on 2026-04-01 (valid until 2026-07-01), or
 * with the given medical state.
 *
 * @param  array<string, mixed>  $attributes
 */
function workflowPassport(string $medical = 'fit', array $attributes = []): PassportEntry
{
    $passport = PassportEntry::factory()->create([
        'passport_received_date' => '2026-01-15',
        'passport_expiry_date' => '2035-01-01',
        ...$attributes,
    ]);

    $factory = MedicalRecord::factory()->for($passport);

    match ($medical) {
        'pending' => null,
        'fit' => $factory->on('2026-04-01')->create(),
        'expiring_soon' => $factory->validUntil('2026-05-10')->create(),
        // Valid until yesterday in Dhaka (still today in UTC).
        'expired' => $factory->validUntil('2026-04-30')->create(),
        'unfit' => $factory->unfit()->on('2026-04-01')->create(),
    };

    return $passport->fresh();
}

/**
 * Add completed records for the given steps, in order, a day apart from 2026-04-02.
 *
 * @param  list<string>  $steps
 */
function completeSteps(PassportEntry $passport, array $steps, ?User $creator = null): PassportEntry
{
    foreach (array_values($steps) as $index => $step) {
        $factory = PassportStepRecord::factory()->for($passport)->step($step)->on(Carbon::parse('2026-04-02')->addDays($index)->toDateString());
        $factory = $step === 'flight' ? $factory->flight('2026-04-30') : $factory;
        $factory = $creator ? $factory->createdBy($creator) : $factory;
        $factory->create();
    }

    return $passport->fresh();
}

/**
 * A valid request body for a step record.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stepPayload(string $step, string $status, array $overrides = []): array
{
    $payload = ['status' => $status, 'step_date' => '2026-04-20'];

    if ($status === 'completed' && $step !== 'flight') {
        $payload['reference_no'] = 'REF-12345';
    }

    if ($step === 'flight' && $status !== 'rejected') {
        $payload['details'] = [
            'airline' => 'Sample Air',
            'flight_no' => 'SA123',
            'departure_date' => $status === 'completed' ? '2026-04-30' : '2026-05-20',
        ];
    }

    return [...$payload, ...$overrides];
}

/**
 * [current_stage, stage_status, stage_status_date] as stored.
 *
 * @return array{string, string|null, string|null}
 */
function storedPosition(PassportEntry $passport): array
{
    $passport = $passport->fresh();

    return [$passport->current_stage, $passport->stage_status, $passport->stage_status_date?->toDateString()];
}

describe('access', function () {
    it('rejects unauthenticated requests', function (string $method, string $uri) {
        $this->json($method, $uri)->assertUnauthorized();
    })->with([
        ['GET', '/api/data-entry/workflow/config'],
        ['GET', '/api/data-entry/passports/1/steps'],
        ['POST', '/api/data-entry/passports/1/steps/calling'],
        ['PATCH', '/api/data-entry/step-records/1'],
        ['DELETE', '/api/data-entry/step-records/1'],
    ]);

    it('rejects the accounts role', function () {
        $passport = completeSteps(workflowPassport(), ['calling']);
        $record = PassportStepRecord::sole();
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->getJson('/api/data-entry/workflow/config')->assertForbidden();
        $this->getJson("/api/data-entry/passports/{$passport->id}/steps")->assertForbidden();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa", stepPayload('visa', 'in_process'))->assertForbidden();
        $this->patchJson("/api/data-entry/step-records/{$record->id}", ['remarks' => 'x'])->assertForbidden();
        $this->deleteJson("/api/data-entry/step-records/{$record->id}")->assertForbidden();
    });

    it('returns 404 for an unknown step', function () {
        $passport = workflowPassport();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/medical", stepPayload('calling', 'in_process'))->assertNotFound();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/bogus", stepPayload('calling', 'in_process'))->assertNotFound();
    });
});

describe('config', function () {
    it('describes the six steps for the SPA forms', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->getJson('/api/data-entry/workflow/config')->assertOk();

        expect($response->json('data.steps.*.key'))->toBe(['passport', 'medical', 'calling', 'visa', 'bmet', 'flight'])
            ->and($response->json('data.steps.*.order'))->toBe([1, 2, 3, 4, 5, 6])
            ->and($response->json('data.steps.2.label'))->toBe('Calling / Work Permit')
            ->and($response->json('data.steps.2.short_label'))->toBe('Calling')
            ->and($response->json('data.steps.2.statuses'))->toBe([
                ['value' => 'in_process', 'label' => 'Applied'],
                ['value' => 'completed', 'label' => 'Approved'],
                ['value' => 'rejected', 'label' => 'Rejected'],
            ])
            ->and($response->json('data.steps.2.warning_days'))->toBe(30)
            ->and($response->json('data.steps.5.statuses.*.label'))->toBe(['Ticket booked', 'Departed', 'Cancelled'])
            ->and($response->json('data.steps.5.fields.*.name'))->toBe(['step_date', 'airline', 'flight_no', 'departure_date', 'ticket_pnr'])
            ->and($response->json('data.steps.5.fields.3'))->toMatchArray(['type' => 'date', 'in_details' => true, 'required_for' => ['in_process', 'completed']])
            ->and($response->json('data.steps.3.fields.0'))->toMatchArray(['name' => 'reference_no', 'label' => 'Visa number', 'max' => 100, 'required_for' => ['completed']]);
    });
});

describe('gating', function () {
    it('cannot record a step before the previous one is completed', function (array $done, ?string $callingStatus, string $step) {
        $passport = completeSteps(workflowPassport(), $done);

        if ($callingStatus) {
            PassportStepRecord::factory()->for($passport)->step('calling')->status(StepStatus::from($callingStatus))->on('2026-04-10')->create();
        }

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/{$step}", stepPayload($step, 'in_process'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'previous_step_incomplete');
    })->with([
        'visa with calling waiting' => [[], null, 'visa'],
        'visa with calling in process' => [[], 'in_process', 'visa'],
        'visa with calling rejected' => [[], 'rejected', 'visa'],
        'flight with BMET waiting' => [['calling', 'visa'], null, 'flight'],
    ]);

    it('needs a fit medical that is still valid today (Dhaka) for calling', function (string $medical, bool $allowed) {
        $passport = workflowPassport($medical);
        Sanctum::actingAs(User::factory()->admin()->create());

        $response = $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling", stepPayload('calling', 'in_process'));

        $allowed
            ? $response->assertCreated()
            : $response->assertUnprocessable()->assertJsonPath('code', 'medical_not_valid');
    })->with([
        'fit' => ['fit', true],
        'fit, expiring soon' => ['expiring_soon', true],
        'fit, valid until yesterday in Dhaka' => ['expired', false],
        'pending' => ['pending', false],
        'unfit' => ['unfit', false],
    ]);

    it('does not allow a second record for a completed step', function () {
        $passport = completeSteps(workflowPassport(), ['calling']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling", stepPayload('calling', 'in_process'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_already_completed');
    });

    it('moves an in-process step forward with PATCH, not a new record', function () {
        $passport = workflowPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $id = $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling", stepPayload('calling', 'in_process'))
            ->assertCreated()
            ->assertJsonPath('passport.current_stage', 'calling')
            ->assertJsonPath('passport.stage_status', 'in_process')
            ->json('data.id');

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling", stepPayload('calling', 'completed'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_in_process');

        $this->patchJson("/api/data-entry/step-records/{$id}", ['status' => 'completed', 'reference_no' => 'WP-998877'])
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Approved')
            ->assertJsonPath('passport.current_stage', 'visa')
            ->assertJsonPath('passport.stage_status', 'waiting');

        expect(PassportStepRecord::count())->toBe(1);
    });

    it('allows a new record after a rejection and keeps the history', function () {
        $passport = workflowPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling", stepPayload('calling', 'rejected', ['step_date' => '2026-04-10']))
            ->assertCreated()
            ->assertJsonPath('passport.stage_status', 'rejected');
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling", stepPayload('calling', 'in_process', ['step_date' => '2026-04-15']))
            ->assertCreated()
            ->assertJsonPath('passport.stage_status', 'in_process');

        $this->getJson("/api/data-entry/passports/{$passport->id}/steps")
            ->assertOk()
            ->assertJsonCount(2, 'data.calling')
            ->assertJsonPath('data.calling.0.status', 'in_process')
            ->assertJsonPath('data.calling.1.status', 'rejected')
            ->assertJsonPath('data.visa', [])
            ->assertJsonPath('data.flight', []);
    });
});

describe('recompute', function () {
    it('stores every stage and status with its date as the steps go on', function () {
        $passport = workflowPassport();
        Sanctum::actingAs(User::factory()->admin()->create());
        $post = fn (string $step, array $payload) => $this->postJson("/api/data-entry/passports/{$passport->id}/steps/{$step}", $payload)->assertCreated()->json('data.id');

        // Fit medical on 2026-04-01: waiting for calling since then.
        expect(storedPosition($passport))->toBe(['calling', 'waiting', '2026-04-01']);

        $calling = $post('calling', stepPayload('calling', 'in_process', ['step_date' => '2026-04-05']));
        expect(storedPosition($passport))->toBe(['calling', 'in_process', '2026-04-05']);

        $this->patchJson("/api/data-entry/step-records/{$calling}", ['status' => 'completed', 'reference_no' => 'WP-1', 'step_date' => '2026-04-08'])->assertOk();
        expect(storedPosition($passport))->toBe(['visa', 'waiting', '2026-04-08']);

        $post('visa', stepPayload('visa', 'rejected', ['step_date' => '2026-04-10']));
        expect(storedPosition($passport))->toBe(['visa', 'rejected', '2026-04-10']);

        $post('visa', stepPayload('visa', 'completed', ['step_date' => '2026-04-12', 'valid_until' => '2026-10-12']));
        expect(storedPosition($passport))->toBe(['bmet', 'waiting', '2026-04-12']);

        $post('bmet', stepPayload('bmet', 'completed', ['step_date' => '2026-04-15']));
        expect(storedPosition($passport))->toBe(['flight', 'waiting', '2026-04-15']);

        $flight = $post('flight', stepPayload('flight', 'in_process', ['step_date' => '2026-04-18']));
        expect(storedPosition($passport))->toBe(['flight', 'in_process', '2026-04-18']);

        $this->patchJson("/api/data-entry/step-records/{$flight}", ['status' => 'completed', 'details' => ['departure_date' => '2026-04-29']])->assertOk();
        expect(storedPosition($passport))->toBe(['completed', null, '2026-04-29']);
    });

    it('keeps an unfit passport at medical / unfit and a pending one at medical / pending', function () {
        expect(storedPosition(workflowPassport('unfit')))->toBe(['medical', 'unfit', '2026-04-01'])
            ->and(storedPosition(workflowPassport('pending')))->toBe(['medical', 'pending', '2026-01-15']);
    });

    it('counts a fit medical whose validity ended as passed', function () {
        $passport = completeSteps(workflowPassport('expired'), ['calling', 'visa']);

        expect(storedPosition($passport))->toBe(['bmet', 'waiting', '2026-04-03']);
    });

    it('does not change the stored values as the date passes', function () {
        $passports = [
            workflowPassport('pending'),
            workflowPassport('unfit'),
            workflowPassport('fit'),
            completeSteps(workflowPassport('fit'), ['calling']),
            completeSteps(workflowPassport('fit'), ['calling', 'visa', 'bmet', 'flight']),
        ];
        $before = array_map(fn (PassportEntry $passport) => storedPosition($passport), $passports);

        Carbon::setTestNow(Carbon::parse('2027-06-01 00:30', 'Asia/Dhaka')->utc());
        app(WorkflowService::class)->recomputeAll();

        expect(array_map(fn (PassportEntry $passport) => storedPosition($passport), $passports))->toBe($before);
    });

    it('backfills existing passports with the recompute command', function () {
        $pending = workflowPassport('pending');
        $unfit = workflowPassport('unfit');
        $atVisa = completeSteps(workflowPassport(), ['calling']);
        $done = completeSteps(workflowPassport(), ['calling', 'visa', 'bmet', 'flight']);
        $deleted = completeSteps(workflowPassport(), ['calling']);
        $deleted->delete();

        // As the columns are right after the migration adds them, before the backfill.
        DB::table('passport_entries')->update(['current_stage' => 'medical', 'stage_status' => null, 'stage_status_date' => null]);

        $this->artisan('workflow:recompute-stages', ['--chunk' => 2])
            ->expectsOutput('Recomputed 5 passports.')
            ->assertSuccessful();

        expect(storedPosition($pending))->toBe(['medical', 'pending', '2026-01-15'])
            ->and(storedPosition($unfit))->toBe(['medical', 'unfit', '2026-04-01'])
            ->and(storedPosition($atVisa))->toBe(['visa', 'waiting', '2026-04-02'])
            ->and(storedPosition($done))->toBe(['completed', null, '2026-04-30'])
            ->and(PassportEntry::withTrashed()->find($deleted->id)->current_stage)->toBe('visa');
    });
});

describe('validation', function () {
    it('rejects invalid input', function (string $step, string $status, array $overrides, string $field) {
        $passport = completeSteps(workflowPassport(), array_slice(['calling', 'visa', 'bmet'], 0, array_search($step, ['calling', 'visa', 'bmet', 'flight'], true)));
        Sanctum::actingAs(User::factory()->admin()->create());

        $payload = stepPayload($step, $status, $overrides);
        foreach ($overrides as $key => $value) {
            if ($value === '__unset') {
                data_forget($payload, $key);
            }
        }

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/{$step}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(PassportStepRecord::where('step_key', $step)->count())->toBe(0);
    })->with([
        'calling approved without a permit number' => ['calling', 'completed', ['reference_no' => '__unset'], 'reference_no'],
        'visa issued without a visa number' => ['visa', 'completed', ['reference_no' => null], 'reference_no'],
        'BMET cleared without a clearance number' => ['bmet', 'completed', ['reference_no' => ''], 'reference_no'],
        'reference number too long' => ['visa', 'in_process', ['reference_no' => str_repeat('A', 101)], 'reference_no'],
        'missing step date' => ['calling', 'in_process', ['step_date' => '__unset'], 'step_date'],
        'step date in the future (Dhaka)' => ['calling', 'in_process', ['step_date' => '2026-05-02'], 'step_date'],
        'valid until before the step date' => ['visa', 'completed', ['valid_until' => '2026-04-19'], 'valid_until'],
        'valid until on the step date' => ['visa', 'completed', ['valid_until' => '2026-04-20'], 'valid_until'],
        'valid until more than 5 years ahead' => ['visa', 'completed', ['valid_until' => '2031-05-02'], 'valid_until'],
        'bad status' => ['calling', 'approved', [], 'status'],
        'missing status' => ['calling', 'in_process', ['status' => '__unset'], 'status'],
        'remarks too long' => ['calling', 'in_process', ['remarks' => str_repeat('a', 1001)], 'remarks'],
        'flight booked without airline' => ['flight', 'in_process', ['details.airline' => '__unset'], 'details.airline'],
        'flight booked without flight number' => ['flight', 'in_process', ['details.flight_no' => '__unset'], 'details.flight_no'],
        'flight booked without departure date' => ['flight', 'in_process', ['details.departure_date' => '__unset'], 'details.departure_date'],
        'flight number with symbols' => ['flight', 'in_process', ['details' => ['airline' => 'Sample Air', 'flight_no' => 'SA-12', 'departure_date' => '2026-05-20']], 'details.flight_no'],
        'departed with a future departure date' => ['flight', 'completed', ['details' => ['airline' => 'Sample Air', 'flight_no' => 'SA12', 'departure_date' => '2026-05-02']], 'details.departure_date'],
        'booking date in the future' => ['flight', 'in_process', ['step_date' => '2026-05-03'], 'step_date'],
        'PNR too long' => ['flight', 'in_process', ['details' => ['airline' => 'Sample Air', 'flight_no' => 'SA12', 'departure_date' => '2026-05-20', 'ticket_pnr' => str_repeat('P', 21)]], 'details.ticket_pnr'],
    ]);

    it('accepts today in Dhaka for dates, and a departure today for a departed flight', function () {
        $passport = completeSteps(workflowPassport(), ['calling', 'visa', 'bmet']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/flight", stepPayload('flight', 'completed', [
            'step_date' => '2026-05-01',
            'details' => ['airline' => 'Sample Air', 'flight_no' => 'SA12', 'departure_date' => '2026-05-01'],
        ]))->assertCreated();
    });

    it('allows a cancelled flight without its details', function () {
        $passport = completeSteps(workflowPassport(), ['calling', 'visa', 'bmet']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/flight", stepPayload('flight', 'rejected'))
            ->assertCreated()
            ->assertJsonPath('data.status_label', 'Cancelled');
    });

    it('never takes protected fields or unknown details from the request', function () {
        $passport = completeSteps(workflowPassport(), ['calling', 'visa', 'bmet']);
        $clerk = User::factory()->dataEntry()->create();
        $other = User::factory()->admin()->create();
        Sanctum::actingAs($clerk);

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/flight", [
            ...stepPayload('flight', 'in_process'),
            'created_by' => $other->id,
            'updated_by' => $other->id,
            'deleted_by' => $other->id,
            'step_key' => 'calling',
            'passport_entry_id' => workflowPassport()->id,
            'reference_no' => 'IGNORED?',
            'details' => ['airline' => 'Sample Air', 'flight_no' => 'SA123', 'departure_date' => '2026-05-20', 'seat' => '12A'],
        ])->assertCreated()->assertJsonPath('data.recorded_by.id', $clerk->id);

        $record = PassportStepRecord::query()->where('step_key', 'flight')->sole();

        expect($record)
            ->created_by->toBe($clerk->id)
            ->updated_by->toBe($clerk->id)
            ->deleted_by->toBeNull()
            ->passport_entry_id->toBe($passport->id)
            ->and($record->details)->toBe(['airline' => 'Sample Air', 'flight_no' => 'SA123', 'departure_date' => '2026-05-20']);
    });

    it('validates a PATCH against the record as a whole', function () {
        $passport = workflowPassport();
        $record = PassportStepRecord::factory()->for($passport)->status(StepStatus::InProcess)->on('2026-04-10')->create(['reference_no' => null]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/data-entry/step-records/{$record->id}", ['status' => 'completed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_no');
        $this->patchJson("/api/data-entry/step-records/{$record->id}", ['valid_until' => '2026-04-09'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('valid_until');
    });
});

describe('warnings', function () {
    it('warns, without blocking, when the passport expires within 6 months of the departure', function () {
        $passport = completeSteps(workflowPassport('fit', ['passport_expiry_date' => '2026-11-01']), ['calling', 'visa', 'bmet']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/flight", stepPayload('flight', 'in_process'))
            ->assertCreated()
            ->assertJsonPath('warnings.*.code', ['passport_expires_soon_after_departure']);
    });

    it('warns at the visa step when the passport expires within 6 months of today', function () {
        $passport = completeSteps(workflowPassport('fit', ['passport_expiry_date' => '2026-10-31']), ['calling']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa", stepPayload('visa', 'in_process'))
            ->assertCreated()
            ->assertJsonPath('warnings.*.code', ['passport_expires_soon']);

        // Exactly 6 months is fine.
        $other = completeSteps(workflowPassport('fit', ['passport_expiry_date' => '2026-11-01']), ['calling']);
        $this->postJson("/api/data-entry/passports/{$other->id}/steps/visa", stepPayload('visa', 'in_process'))
            ->assertJsonPath('warnings', []);
    });

    it('warns when the visa runs out before the departure', function () {
        $passport = workflowPassport();
        completeSteps($passport, ['calling']);
        PassportStepRecord::factory()->for($passport)->step('visa')->on('2026-04-03')->validUntil('2026-05-10')->create();
        PassportStepRecord::factory()->for($passport)->step('bmet')->on('2026-04-04')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/flight", stepPayload('flight', 'in_process'))
            ->assertCreated()
            ->assertJsonPath('warnings.*.code', ['visa_expires_before_departure']);
    });

    it('warns when the medical has expired at a later step', function () {
        $passport = completeSteps(workflowPassport('expired'), ['calling']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertOk()
            ->assertJsonPath('data.warnings.*.code', ['medical_expired'])
            ->assertJsonPath('data.workflow.steps.1.state', 'expired');
    });
});

describe('validity status', function () {
    it('works out validity with the Dhaka date and the step warning days', function (string $validUntil, string $status, int $daysLeft) {
        $passport = completeSteps(workflowPassport(), ['calling']);
        PassportStepRecord::factory()->for($passport)->step('visa')->on('2026-04-10')->validUntil($validUntil)->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports/{$passport->id}/steps")
            ->assertJsonPath('data.visa.0.validity_status', $status)
            ->assertJsonPath('data.visa.0.days_left', $daysLeft);
    })->with([
        'exactly 30 days left' => ['2026-05-31', 'expiring_soon', 30],
        '31 days left' => ['2026-06-01', 'valid', 31],
        'valid until today' => ['2026-05-01', 'expiring_soon', 0],
        'valid until yesterday (still today in UTC)' => ['2026-04-30', 'expired', -1],
    ]);

    it('has no validity status without valid_until', function () {
        $passport = completeSteps(workflowPassport(), ['calling']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports/{$passport->id}/steps")
            ->assertJsonPath('data.calling.0.validity_status', null)
            ->assertJsonPath('data.calling.0.days_left', null);
    });
});

describe('editing', function () {
    it('lets data entry edit only their own records, and only before a later step has records', function () {
        $clerk = User::factory()->dataEntry()->create();
        $passport = workflowPassport();
        $own = PassportStepRecord::factory()->for($passport)->createdBy($clerk)->status(StepStatus::InProcess)->on('2026-04-10')->create();
        $others = PassportStepRecord::factory()->for(workflowPassport())->status(StepStatus::InProcess)->on('2026-04-10')->create();
        Sanctum::actingAs($clerk);

        $this->patchJson("/api/data-entry/step-records/{$own->id}", ['remarks' => 'Corrected'])
            ->assertOk()
            ->assertJsonPath('data.can.update', true)
            ->assertJsonPath('data.can.delete', false);
        $this->patchJson("/api/data-entry/step-records/{$others->id}", ['remarks' => 'Corrected'])->assertForbidden();

        // Once the next step has a record, the earlier one is closed to data entry.
        $this->patchJson("/api/data-entry/step-records/{$own->id}", ['status' => 'completed', 'reference_no' => 'WP-1'])->assertOk();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa", stepPayload('visa', 'in_process'))->assertCreated();
        $this->patchJson("/api/data-entry/step-records/{$own->id}", ['remarks' => 'Again'])->assertForbidden();
    });

    it('lets admin and super admin edit any record, but never out of order', function (string $role) {
        $passport = completeSteps(workflowPassport(), ['calling']);
        $calling = PassportStepRecord::sole();
        PassportStepRecord::factory()->for($passport)->step('visa')->status(StepStatus::InProcess)->on('2026-04-20')->create();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->patchJson("/api/data-entry/step-records/{$calling->id}", ['remarks' => 'Checked'])->assertOk();

        $this->patchJson("/api/data-entry/step-records/{$calling->id}", ['status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'order_violation');

        expect($calling->fresh()->status)->toBe(StepStatus::Completed)
            ->and(storedPosition($passport))->toBe(['visa', 'in_process', '2026-04-20']);
    })->with(['admin', 'superAdmin']);
});

describe('deleting', function () {
    it('lets only admin and super admin delete', function () {
        $clerk = User::factory()->dataEntry()->create();
        $passport = workflowPassport();
        $record = PassportStepRecord::factory()->for($passport)->createdBy($clerk)->create();
        Sanctum::actingAs($clerk);

        $this->deleteJson("/api/data-entry/step-records/{$record->id}")->assertForbidden();
    });

    it('refuses while a later step has records', function () {
        $passport = completeSteps(workflowPassport(), ['calling', 'visa']);
        $calling = PassportStepRecord::query()->where('step_key', 'calling')->sole();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/data-entry/step-records/{$calling->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'later_step_exists');
    });

    it('makes the previous latest record current again', function (string $role) {
        $passport = workflowPassport();
        PassportStepRecord::factory()->for($passport)->status(StepStatus::Rejected)->on('2026-04-05')->create();
        $approved = PassportStepRecord::factory()->for($passport)->on('2026-04-10')->create();
        $user = User::factory()->{$role}()->create();
        Sanctum::actingAs($user);

        expect(storedPosition($passport))->toBe(['visa', 'waiting', '2026-04-10']);

        $this->deleteJson("/api/data-entry/step-records/{$approved->id}")->assertNoContent();

        expect(storedPosition($passport))->toBe(['calling', 'rejected', '2026-04-05'])
            ->and(PassportStepRecord::withTrashed()->find($approved->id)->deleted_by)->toBe($user->id);
    })->with(['admin', 'superAdmin']);
});

describe('medical interplay', function () {
    it('closes medical changes to data entry once calling has a record', function () {
        $clerk = User::factory()->dataEntry()->create();
        $passport = workflowPassport();
        $medical = MedicalRecord::sole();
        $medical->forceFill(['created_by' => $clerk->id])->save();
        PassportStepRecord::factory()->for($passport)->status(StepStatus::InProcess)->on('2026-04-10')->create();
        Sanctum::actingAs($clerk);

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-25', 'result' => 'fit'])->assertForbidden();
        $this->patchJson("/api/data-entry/medicals/{$medical->id}", ['remarks' => 'x'])->assertForbidden();
    });

    it('rejects an unfit result while later steps have records, for admin too', function () {
        $passport = completeSteps(workflowPassport(), ['calling']);
        $medical = MedicalRecord::sole();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-25', 'result' => 'unfit'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'later_steps_exist');
        $this->patchJson("/api/data-entry/medicals/{$medical->id}", ['result' => 'unfit'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'later_steps_exist');
        $this->deleteJson("/api/data-entry/medicals/{$medical->id}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'later_steps_exist');

        expect(MedicalRecord::count())->toBe(1)
            ->and($medical->fresh()->result->value)->toBe('fit');

        // A fit re-test is fine.
        $this->postJson("/api/data-entry/passports/{$passport->id}/medicals", ['medical_date' => '2026-04-25', 'result' => 'fit'])->assertCreated();
        expect(storedPosition($passport))->toBe(['visa', 'waiting', '2026-04-02']);
    });
});

describe('latest status', function () {
    it('shows the latest status of each step', function (array $done, ?array $current, array $expected) {
        $passport = completeSteps(workflowPassport(), $done);

        if ($current) {
            [$step, $status] = $current;
            $factory = PassportStepRecord::factory()->for($passport)->step($step)->status(StepStatus::from($status))->on('2026-04-20');
            ($step === 'flight' ? $factory->flight('2026-05-20') : $factory)->create();
        }

        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect($this->getJson('/api/data-entry/passports?view=overview&medical_status=all')->json('data.0.latest_status'))
            ->toMatchArray($expected);
    })->with([
        'calling applied' => [[], ['calling', 'in_process'], ['text' => 'Calling / Work Permit: Applied', 'tone' => 'blue', 'date' => '2026-04-20']],
        'calling rejected' => [[], ['calling', 'rejected'], ['text' => 'Calling / Work Permit: Rejected', 'tone' => 'red', 'date' => '2026-04-20']],
        'calling approved, visa waiting' => [['calling'], null, ['stage' => 'visa', 'text' => 'Visa: Waiting', 'tone' => 'amber', 'date' => '2026-04-02']],
        'visa submitted' => [['calling'], ['visa', 'in_process'], ['text' => 'Visa: Submitted', 'tone' => 'blue', 'date' => '2026-04-20']],
        'visa rejected' => [['calling'], ['visa', 'rejected'], ['text' => 'Visa: Rejected', 'tone' => 'red', 'date' => '2026-04-20']],
        'visa issued, BMET waiting' => [['calling', 'visa'], null, ['text' => 'BMET Clearance: Waiting', 'tone' => 'amber', 'date' => '2026-04-03']],
        'BMET submitted' => [['calling', 'visa'], ['bmet', 'in_process'], ['text' => 'BMET Clearance: Submitted', 'tone' => 'blue', 'date' => '2026-04-20']],
        'BMET rejected' => [['calling', 'visa'], ['bmet', 'rejected'], ['text' => 'BMET Clearance: Rejected', 'tone' => 'red', 'date' => '2026-04-20']],
        'BMET cleared, flight waiting' => [['calling', 'visa', 'bmet'], null, ['text' => 'Flight: Waiting', 'tone' => 'amber', 'date' => '2026-04-04']],
        'ticket booked' => [['calling', 'visa', 'bmet'], ['flight', 'in_process'], ['text' => 'Flight: Ticket booked', 'tone' => 'blue', 'date' => '2026-04-20']],
        'flight cancelled' => [['calling', 'visa', 'bmet'], ['flight', 'rejected'], ['text' => 'Flight: Cancelled', 'tone' => 'red', 'date' => '2026-04-20']],
        'departed' => [['calling', 'visa', 'bmet', 'flight'], null, ['stage' => 'completed', 'status' => 'completed', 'text' => 'Completed: Departed', 'tone' => 'green', 'date' => '2026-04-30']],
    ]);
});

describe('passport resource', function () {
    it('shows the six steps with their latest records', function () {
        $clerk = User::factory()->dataEntry()->create(['name' => 'Rafi']);
        $passport = completeSteps(workflowPassport(), ['calling'], $clerk);
        PassportStepRecord::factory()->for($passport)->step('visa')->status(StepStatus::InProcess)->on('2026-04-20')->createdBy($clerk)->create(['reference_no' => 'V-1', 'remarks' => 'Waiting for embassy']);
        Sanctum::actingAs($clerk);

        $response = $this->getJson("/api/data-entry/passports/{$passport->id}")->assertOk();

        expect($response->json('data.current_stage'))->toBe('visa')
            ->and($response->json('data.stage_status'))->toBe('in_process')
            ->and($response->json('data.workflow.current_step'))->toBe('visa')
            ->and($response->json('data.workflow.steps.*.state'))->toBe(['done', 'passed', 'completed', 'in_process', 'locked', 'locked'])
            ->and($response->json('data.workflow.steps.5.record'))->toBeNull()
            ->and($response->json('data.workflow.steps.3.record'))->toMatchArray([
                'status' => 'in_process',
                'status_label' => 'Submitted',
                'reference_no' => 'V-1',
                'step_date' => '2026-04-20',
                'valid_until' => null,
                'validity_status' => null,
                'remarks' => 'Waiting for embassy',
                'recorded_by' => ['id' => $clerk->id, 'name' => 'Rafi'],
            ])
            ->and($response->json('data.warnings'))->toBe([]);
    });

    it('shows a completed passport', function () {
        $passport = completeSteps(workflowPassport(), ['calling', 'visa', 'bmet', 'flight']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->getJson("/api/data-entry/passports/{$passport->id}")->assertOk();

        expect($response->json('data.current_stage'))->toBe('completed')
            ->and($response->json('data.stage_status'))->toBeNull()
            ->and($response->json('data.workflow.current_step'))->toBeNull()
            ->and($response->json('data.workflow.steps.*.state'))->toBe(['done', 'passed', 'completed', 'completed', 'completed', 'completed'])
            ->and($response->json('data.workflow.steps.5.record.status_label'))->toBe('Departed');
    });
});

describe('passport list', function () {
    /**
     * Passports at different stages; the returned keys name them.
     *
     * @return array<string, PassportEntry>
     */
    function stagedPassports(): array
    {
        $passports = [
            'pending' => workflowPassport('pending'),
            'unfit' => workflowPassport('unfit'),
            'calling_waiting' => workflowPassport(),
            'calling_rejected' => workflowPassport(),
            'visa_in_process' => completeSteps(workflowPassport(), ['calling']),
            'visa_rejected' => completeSteps(workflowPassport(), ['calling']),
            'completed' => completeSteps(workflowPassport(), ['calling', 'visa', 'bmet', 'flight']),
        ];

        PassportStepRecord::factory()->for($passports['calling_rejected'])->status(StepStatus::Rejected)->on('2026-04-10')->create();
        PassportStepRecord::factory()->for($passports['visa_in_process'])->step('visa')->status(StepStatus::InProcess)->on('2026-04-10')->create();
        PassportStepRecord::factory()->for($passports['visa_rejected'])->step('visa')->status(StepStatus::Rejected)->on('2026-04-10')->create();

        return array_map(fn (PassportEntry $passport) => $passport->fresh(), $passports);
    }

    it('filters by stage and stage status, together with other filters', function () {
        $p = stagedPassports();
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $ids = fn (string $query) => $this->getJson("/api/data-entry/passports?per_page=100&{$query}")->assertOk()->json('data.*.id');

        expect($ids(''))->toHaveCount(6)->not->toContain($p['unfit']->id)->toContain($p['completed']->id)
            ->and($ids('stage=visa'))->toEqualCanonicalizing([$p['visa_in_process']->id, $p['visa_rejected']->id])
            ->and($ids('stage=visa&stage_status=rejected'))->toBe([$p['visa_rejected']->id])
            ->and($ids('stage_status=rejected'))->toEqualCanonicalizing([$p['calling_rejected']->id, $p['visa_rejected']->id])
            ->and($ids('stage=completed'))->toBe([$p['completed']->id])
            ->and($ids('stage=medical'))->toBe([$p['pending']->id])
            ->and($ids('stage=medical&medical_status=all'))->toEqualCanonicalizing([$p['pending']->id, $p['unfit']->id])
            ->and($ids('stage_status=unfit&medical_status=all'))->toBe([$p['unfit']->id])
            ->and($ids("stage=visa&company_id={$p['visa_rejected']->company_id}"))->toBe([$p['visa_rejected']->id])
            ->and($ids('stage=calling&medical_status=fit'))->toEqualCanonicalizing([$p['calling_waiting']->id, $p['calling_rejected']->id]);

        $this->getJson('/api/data-entry/passports?stage=visa&per_page=1&page=2&sort=created_at&direction=asc')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.*.id', [$p['visa_rejected']->id]);
    });

    it('rejects unknown stage values', function (string $query) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports?{$query}")->assertUnprocessable();
    })->with(['stage=step3', 'stage_status=completed', 'stage=VISA']);

    it('runs a fixed number of queries however many passports are listed', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $countQueries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/data-entry/passports?medical_status=all&per_page=100')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        stagedPassports();
        $few = $countQueries();

        stagedPassports();
        stagedPassports();

        // count + page + country + reference + company + company country
        // + current medical + its creator + step records + their creators.
        expect($countQueries())->toBe($few)->toBe(10);
    });

    it('counts every stage in the summary', function () {
        stagedPassports();
        completeSteps(workflowPassport(), ['calling'])->delete();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        DB::enableQueryLog();
        $response = $this->getJson('/api/data-entry/workflow/summary')->assertOk();

        expect(DB::getQueryLog())->toHaveCount(2)
            ->and($response->json('data.stages'))->toBe([
                'medical' => ['total' => 2, 'pending' => 1, 'unfit' => 1],
                'calling' => ['total' => 2, 'waiting' => 1, 'in_process' => 0, 'rejected' => 1],
                'visa' => ['total' => 2, 'waiting' => 0, 'in_process' => 1, 'rejected' => 1],
                'bmet' => ['total' => 0, 'waiting' => 0, 'in_process' => 0, 'rejected' => 0],
                'flight' => ['total' => 0, 'waiting' => 0, 'in_process' => 0, 'rejected' => 0],
            ])
            ->and($response->json('data.completed'))->toBe(1)
            ->and($response->json('data.step1.total_all'))->toBe(7)
            ->and($response->json('data.steps.*.key'))->toBe(['passport', 'medical', 'calling', 'visa', 'bmet', 'flight']);
    });
});

describe('development seeder', function () {
    it('covers every stage, refusing to run in production', function () {
        $this->seed();

        $stages = PassportEntry::query()->get(['current_stage', 'stage_status'])
            ->map(fn (PassportEntry $passport) => $passport->current_stage.'/'.$passport->stage_status)
            ->unique()->sort()->values()->all();

        expect($stages)->toContain('medical/pending', 'medical/unfit', 'calling/waiting', 'calling/in_process',
            'visa/in_process', 'visa/rejected', 'bmet/waiting', 'bmet/in_process', 'flight/waiting', 'flight/in_process', 'completed/')
            ->and(PassportStepRecord::query()->where('step_key', 'calling')->where('status', 'rejected')->exists())->toBeTrue();

        $count = PassportStepRecord::count();
        $this->seed();
        expect(PassportStepRecord::count())->toBe($count);

        app()->detectEnvironment(fn () => 'production');
        PassportStepRecord::query()->forceDelete();
        app(WorkflowDataSeeder::class)->run(app(WorkflowService::class));
        expect(PassportStepRecord::count())->toBe(0);
    });
});
