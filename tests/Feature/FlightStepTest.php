<?php

use App\Enums\StepStatus;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Today in Dhaka is 2026-05-01 (00:30), while UTC is still on 2026-04-30.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

/**
 * A passport permitted on 2026-04-02, with the given later records [step =>
 * status]: visa on 2026-04-03 (issued then, valid until $visaUntil), BMET on
 * 2026-04-04, a flight booked for 2026-05-20 (confirmed on 2026-04-28).
 *
 * @param  array<string, StepStatus>  $records
 * @param  array<string, mixed>  $attributes
 */
function flightPassport(array $records = [], string $visaUntil = '2026-10-03', array $attributes = []): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create([
        'passport_expiry_date' => '2035-01-01',
        'entry_mode' => 'online',
        ...$attributes,
    ]);
    MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
    PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
        ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);

    foreach ($records as $step => $status) {
        $done = $status === StepStatus::Completed;
        $factory = PassportStepRecord::factory()->for($passport)->step($step)->status($status);

        match ($step) {
            'visa' => $factory->on('2026-04-03')->create(['reference_no' => null, ...($done ? ['details' => ['issue_date' => '2026-04-03'], 'valid_until' => $visaUntil] : [])]),
            'bmet' => $factory->on('2026-04-04')->create([
                'reference_no' => null,
                'details' => ['training_certificate_date' => '2026-03-20', 'finger_date' => '2026-03-25'],
            ]),
            'flight' => $factory->flight('2026-05-20', '2026-04-28')->create(),
        };
    }

    return $passport->fresh();
}

/**
 * Passport ids in a Flight (or other step) tab.
 *
 * @return list<int>
 */
function flightTab(string $tab, string $step = 'flight'): array
{
    return test()->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}&per_page=100")->assertOk()->json('data.*.id');
}

function flightUrl(PassportEntry $passport, string $action): string
{
    return "/api/data-entry/passports/{$passport->id}/steps/flight/{$action}";
}

/**
 * The passport's row on a Flight tab.
 *
 * @return array<string, mixed>
 */
function flightRow(PassportEntry $passport, string $tab = 'pending'): array
{
    return collect(test()->getJson("/api/data-entry/steps/flight/passports?tab={$tab}&per_page=100")->json('data'))->firstWhere('id', $passport->id);
}

function flightStatusText(PassportEntry $passport): string
{
    return collect(test()->getJson('/api/data-entry/passports?view=overview&medical_status=all&per_page=100')->json('data'))
        ->firstWhere('id', $passport->id)['latest_status']['text'];
}

/**
 * @return array<string, StepStatus>
 */
function flightCleared(array $more = []): array
{
    return ['visa' => StepStatus::Completed, 'bmet' => StepStatus::Completed, ...$more];
}

describe('tabs', function () {
    it('puts an issued visa in BMET Pending and Flight Pending in the same request cycle', function () {
        $passport = flightPassport();
        PassportStepRecord::factory()->for($passport)->step('visa')->status(StepStatus::InProcess)->on('2026-04-10')->create(['reference_no' => null]);
        app(WorkflowService::class)->recompute($passport);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/result", ['result' => 'completed', 'issue_date' => '2026-04-20', 'valid_until' => '2026-12-31'])
            ->assertOk()
            ->assertJsonPath('passport.current_stage', 'bmet');

        expect(flightTab('issued', 'visa'))->toBe([$passport->id])
            ->and(flightTab('pending', 'bmet'))->toBe([$passport->id])
            ->and(flightTab('pending'))->toBe([$passport->id]);
    });

    it('has only the Pending and Flight confirmed tabs', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/workflow/config')
            ->assertJsonPath('data.steps.5.tabs', [['key' => 'pending', 'label' => 'Pending'], ['key' => 'confirmed', 'label' => 'Flight confirmed']])
            ->assertJsonPath('data.steps.5.statuses', [['value' => 'in_process', 'label' => 'Booked'], ['value' => 'completed', 'label' => 'Flight confirmed']])
            ->assertJsonPath('data.steps.5.fields.*.name', ['booking_date', 'confirmed_date']);

        expect(array_keys($this->getJson('/api/data-entry/steps/flight/passports')->assertOk()->json('tab_counts')))->toBe(['pending', 'confirmed']);

        foreach (['waiting', 'booked', 'departed', 'cancelled', 'in_process', 'completed', 'rejected'] as $tab) {
            $this->getJson("/api/data-entry/steps/flight/passports?tab={$tab}")->assertUnprocessable()->assertJsonValidationErrors('tab');
        }
    });

    it('gives the same numbers in the list, its tab counts and the summary', function () {
        flightPassport(['visa' => StepStatus::Completed]);
        flightPassport(['visa' => StepStatus::Completed, 'bmet' => StepStatus::InProcess]);
        flightPassport(['visa' => StepStatus::Completed, 'flight' => StepStatus::InProcess]);
        flightPassport(flightCleared());
        flightPassport(flightCleared(['flight' => StepStatus::InProcess]));
        flightPassport(flightCleared(['flight' => StepStatus::Rejected]));
        flightPassport(flightCleared(['flight' => StepStatus::Completed]));
        // Not yet: visa only submitted. Never: deleted.
        flightPassport(['visa' => StepStatus::InProcess]);
        flightPassport(flightCleared())->delete();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $counts = $this->getJson('/api/data-entry/steps/flight/passports')->json('tab_counts');
        $summary = $this->getJson('/api/data-entry/workflow/summary')->json('data');

        expect($counts)->toBe(['pending' => 6, 'confirmed' => 1])
            ->and($summary['step_tabs']['flight'])->toBe($counts)
            ->and($summary['stages']['flight'])->toBe(['total' => 6, 'waiting' => 6])
            ->and($summary['step_tabs']['bmet'])->toBe(['pending' => 3, 'cleared' => 4])
            ->and($summary['stages']['bmet'])->toBe(['total' => 3, 'waiting' => 3]);

        foreach ($counts as $tab => $count) {
            expect(count(flightTab($tab)))->toBe($count, $tab);
        }
    });

    it('returns the flight row fields with can_confirm and missing', function () {
        $nothing = flightPassport(['visa' => StepStatus::Completed], '2026-09-30');
        $booked = flightPassport(['visa' => StepStatus::Completed, 'flight' => StepStatus::InProcess]);
        $bmetOnly = flightPassport(flightCleared());
        $ready = flightPassport(flightCleared(['flight' => StepStatus::InProcess]));
        $confirmed = flightPassport(flightCleared(['flight' => StepStatus::Completed]));
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(flightRow($nothing))->toMatchArray([
            'passport_name' => $nothing->passport_name,
            'passport_number' => $nothing->passport_number,
            'visa_valid_until' => '2026-09-30',
            'bmet_cleared' => false,
            'clearance_date' => null,
            'booking_date' => null,
            'confirmed_date' => null,
            'can_confirm' => false,
            'missing' => ['bmet', 'booking_date'],
        ])
            ->and(flightRow($nothing)['company']['id'])->toBe($nothing->company_id)
            ->and(flightRow($nothing))->toHaveKey('reference')
            ->and(flightRow($booked))->toMatchArray(['booking_date' => '2026-05-20', 'can_confirm' => false, 'missing' => ['bmet']])
            ->and(flightRow($bmetOnly))->toMatchArray(['bmet_cleared' => true, 'clearance_date' => '2026-04-04', 'can_confirm' => false, 'missing' => ['booking_date']])
            ->and(flightRow($ready))->toMatchArray(['bmet_cleared' => true, 'booking_date' => '2026-05-20', 'can_confirm' => true, 'missing' => []])
            ->and(flightRow($confirmed, 'confirmed'))->toMatchArray(['booking_date' => '2026-05-20', 'confirmed_date' => '2026-04-28', 'can_confirm' => false, 'missing' => []]);
    });

    it('shows the status line for each combination, the stage being the earliest step not completed', function () {
        $nothing = flightPassport(['visa' => StepStatus::Completed]);
        $booked = flightPassport(['visa' => StepStatus::Completed, 'flight' => StepStatus::InProcess]);
        $bmetOnly = flightPassport(flightCleared());
        $ready = flightPassport(flightCleared(['flight' => StepStatus::InProcess]));
        $confirmed = flightPassport(flightCleared(['flight' => StepStatus::Completed]));
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(flightStatusText($nothing))->toBe('Visa issued - BMET and flight pending')
            ->and(flightStatusText($booked))->toBe('Flight booked (20-05-2026) - BMET pending')
            ->and(flightStatusText($bmetOnly))->toBe('BMET cleared - flight booking date pending')
            ->and(flightStatusText($ready))->toBe('Ready to confirm flight')
            ->and(flightStatusText($confirmed))->toBe('Flight confirmed (28-04-2026)')
            ->and([$booked->current_stage, $booked->stage_status])->toBe(['bmet', 'waiting'])
            ->and([$bmetOnly->current_stage, $bmetOnly->stage_status])->toBe(['flight', 'waiting'])
            ->and([$ready->current_stage, $ready->stage_status])->toBe(['flight', 'in_process'])
            ->and([$confirmed->current_stage, $confirmed->stage_status])->toBe(['completed', null]);
    });
});

describe('booking', function () {
    it('saves the booking date before BMET is cleared, and changes it', function () {
        $passport = flightPassport(['visa' => StepStatus::Completed]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson(flightUrl($passport, 'booking'), ['booking_date' => '2026-06-15'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_process')
            ->assertJsonPath('data.status_label', 'Booked')
            ->assertJsonPath('data.step_date', '2026-06-15')
            ->assertJsonPath('data.details.booking_date', '2026-06-15')
            ->assertJsonPath('passport.current_stage', 'bmet')
            ->assertJsonPath('passport.stage_status', 'waiting');

        $this->postJson(flightUrl($passport, 'booking'), ['booking_date' => '2026-06-20'])
            ->assertOk()
            ->assertJsonPath('data.details.booking_date', '2026-06-20');

        expect(PassportStepRecord::query()->where('step_key', 'flight')->count())->toBe(1)
            ->and(flightTab('pending'))->toBe([$passport->id])
            ->and(flightTab('pending', 'bmet'))->toBe([$passport->id])
            ->and(flightRow($passport))->toMatchArray(['booking_date' => '2026-06-20', 'missing' => ['bmet']]);
    });

    it('validates the booking date', function (array $body, string $message) {
        $passport = flightPassport(['visa' => StepStatus::Completed]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson(flightUrl($passport, 'booking'), $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['booking_date' => $message]);

        expect(PassportStepRecord::query()->where('step_key', 'flight')->exists())->toBeFalse();
    })->with([
        'missing' => [[], 'Enter the flight booking date.'],
        'bad format' => [['booking_date' => '15/06/2026'], 'The flight booking date must be a date (YYYY-MM-DD).'],
        // The visa was issued on 2026-04-03.
        'before the visa issue date' => [['booking_date' => '2026-04-02'], 'The flight booking date cannot be before the visa issue date (03-04-2026).'],
    ]);

    it('accepts today in Dhaka and the visa issue date itself', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson(flightUrl(flightPassport(['visa' => StepStatus::Completed]), 'booking'), ['booking_date' => '2026-05-01'])->assertCreated();
        $this->postJson(flightUrl(flightPassport(['visa' => StepStatus::Completed]), 'booking'), ['booking_date' => '2026-04-03'])->assertCreated();
    });

    it('needs the visa issued first', function () {
        $passport = flightPassport(['visa' => StepStatus::InProcess]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson(flightUrl($passport, 'booking'), ['booking_date' => '2026-06-15'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'previous_step_incomplete');
    });

    it('refuses to change or clear the booking once the flight is confirmed', function () {
        $passport = flightPassport(flightCleared(['flight' => StepStatus::Completed]));
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson(flightUrl($passport, 'booking'), ['booking_date' => '2026-06-15'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_already_completed');
        $this->deleteJson(flightUrl($passport, 'booking'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_already_completed');

        expect(PassportStepRecord::query()->where('step_key', 'flight')->sole()->detail('booking_date'))->toBe('2026-05-20');
    });

    it('clears the booking date, keeping it in the history', function () {
        $passport = flightPassport(['visa' => StepStatus::Completed, 'flight' => StepStatus::InProcess]);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->deleteJson(flightUrl($passport, 'booking'))
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('passport.current_stage', 'bmet');

        expect(flightRow($passport))->toMatchArray(['booking_date' => null, 'missing' => ['bmet', 'booking_date']])
            ->and(PassportStepRecord::withTrashed()->where('step_key', 'flight')->sole()->deleted_by)->not->toBeNull();

        $this->deleteJson(flightUrl($passport, 'booking'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'booking_not_set');
    });
});

describe('confirm', function () {
    it('refuses to confirm with the right message and missing keys', function (array $records, string $message, array $missing) {
        $passport = flightPassport($records);
        Sanctum::actingAs(User::factory()->admin()->create());
        $before = PassportStepRecord::query()->where('step_key', 'flight')->get()->toArray();

        $this->postJson(flightUrl($passport, 'confirm'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'confirm_requirements_missing')
            ->assertJsonPath('message', $message)
            ->assertJsonPath('missing', $missing);

        expect(PassportStepRecord::query()->where('step_key', 'flight')->get()->toArray())->toBe($before)
            ->and(flightTab('confirmed'))->toBe([]);
    })->with([
        'BMET missing' => [['visa' => StepStatus::Completed, 'flight' => StepStatus::InProcess], 'BMET clearance must be done before confirming the flight', ['bmet']],
        'BMET in progress' => [['visa' => StepStatus::Completed, 'bmet' => StepStatus::InProcess, 'flight' => StepStatus::InProcess], 'BMET clearance must be done before confirming the flight', ['bmet']],
        'booking date missing' => [flightCleared(), 'Enter the flight booking date before confirming the flight', ['booking_date']],
        'both missing' => [['visa' => StepStatus::Completed], 'BMET clearance and the flight booking date are required before confirming the flight', ['bmet', 'booking_date']],
    ]);

    it('confirms when BMET is cleared and the booking date saved, storing today in Dhaka', function () {
        $passport = flightPassport(flightCleared());
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->postJson(flightUrl($passport, 'booking'), ['booking_date' => '2026-06-15'])->assertCreated();

        $this->postJson(flightUrl($passport, 'confirm'))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.status_label', 'Flight confirmed')
            ->assertJsonPath('data.step_date', '2026-05-01')
            ->assertJsonPath('data.details.confirmed_date', '2026-05-01')
            ->assertJsonPath('data.details.booking_date', '2026-06-15')
            ->assertJsonPath('passport.current_stage', 'completed')
            ->assertJsonPath('warnings', []);

        expect(flightTab('confirmed'))->toBe([$passport->id])
            ->and(flightTab('pending'))->toBe([])
            ->and(flightStatusText($passport))->toBe('Flight confirmed (01-05-2026)')
            ->and($this->getJson('/api/data-entry/workflow/summary')->json('data.stages.flight'))->toBe(['total' => 0, 'waiting' => 0]);

        $this->postJson(flightUrl($passport, 'confirm'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_already_completed');
    });

    it('takes no flight details any more', function () {
        $passport = flightPassport(flightCleared(['flight' => StepStatus::InProcess]));
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson(flightUrl($passport, 'confirm'), ['flight_date' => '2026-07-01', 'flight_no' => 'SA1', 'airline' => 'X'])->assertOk();

        expect(PassportStepRecord::query()->where('step_key', 'flight')->sole()->details)
            ->toEqual(['booking_date' => '2026-05-20', 'confirmed_date' => '2026-05-01']);
    });

    it('warns, without blocking, when the visa or the passport expires around the booking date', function () {
        $visa = flightPassport(flightCleared(), '2026-06-01');
        $expiring = flightPassport(flightCleared(), '2026-10-03', ['passport_expiry_date' => '2026-12-01']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson(flightUrl($visa, 'booking'), ['booking_date' => '2026-06-15'])
            ->assertCreated()
            ->assertJsonPath('warnings.*.code', ['visa_expires_before_departure'])
            ->assertJsonPath('warnings.0.message', 'The visa expires before the flight booking date.');
        $this->postJson(flightUrl($visa, 'confirm'))
            ->assertOk()
            ->assertJsonPath('warnings.*.code', ['visa_expires_before_departure']);

        $this->postJson(flightUrl($expiring, 'booking'), ['booking_date' => '2026-06-15'])->assertCreated();
        $this->postJson(flightUrl($expiring, 'confirm'))
            ->assertOk()
            ->assertJsonPath('warnings.*.code', ['passport_expires_soon_after_departure']);
    });
});

describe('undo', function () {
    it('returns a confirmed flight to Pending, keeping its booking date', function () {
        $passport = flightPassport(flightCleared());
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->postJson(flightUrl($passport, 'booking'), ['booking_date' => '2026-06-15'])->assertCreated();
        $this->postJson(flightUrl($passport, 'confirm'))->assertOk();

        $this->deleteJson(flightUrl($passport, 'confirm'))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_process')
            ->assertJsonPath('data.step_date', '2026-06-15')
            ->assertJsonPath('data.details.booking_date', '2026-06-15')
            ->assertJsonMissingPath('data.details.confirmed_date')
            ->assertJsonPath('passport.current_stage', 'flight')
            ->assertJsonPath('passport.stage_status', 'in_process');

        expect(flightTab('pending'))->toBe([$passport->id])
            ->and(flightTab('confirmed'))->toBe([])
            ->and(flightRow($passport))->toMatchArray(['booking_date' => '2026-06-15', 'confirmed_date' => null, 'can_confirm' => true])
            ->and(flightStatusText($passport))->toBe('Ready to confirm flight');

        // Confirmed again on the same record.
        $this->postJson(flightUrl($passport, 'confirm'))->assertOk();
        expect(PassportStepRecord::query()->where('step_key', 'flight')->count())->toBe(1);
    });

    it('refuses to undo a flight that is not confirmed', function (array $records) {
        $passport = flightPassport($records);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson(flightUrl($passport, 'confirm'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_not_completed');
    })->with([
        'no flight record' => [flightCleared()],
        'booked only' => [flightCleared(['flight' => StepStatus::InProcess])],
    ]);

    it('refuses to undo the BMET clearance once the flight is confirmed, not while only booked', function () {
        $confirmed = flightPassport(flightCleared(['flight' => StepStatus::Completed]));
        $booked = flightPassport(flightCleared(['flight' => StepStatus::InProcess]));
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->deleteJson("/api/data-entry/passports/{$confirmed->id}/steps/bmet/clearance")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'later_step_completed');

        $this->deleteJson("/api/data-entry/passports/{$booked->id}/steps/bmet/clearance")
            ->assertOk()
            ->assertJsonPath('passport.current_stage', 'bmet');

        expect(flightRow($booked))->toMatchArray(['booking_date' => '2026-05-20', 'missing' => ['bmet']]);
    });
});

describe('legacy records', function () {
    it('keeps a cancelled flight in the history and the passport in Pending', function () {
        $passport = flightPassport(flightCleared(['flight' => StepStatus::Rejected]));
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(flightTab('pending'))->toBe([$passport->id])
            ->and(flightRow($passport)['missing'])->toBe(['booking_date'])
            ->and(PassportStepRecord::query()->where('step_key', 'flight')->where('status', 'rejected')->count())->toBe(1);

        $this->postJson(flightUrl($passport, 'booking'), ['booking_date' => '2026-05-20'])->assertCreated();
    });

    it('has no flight start or result endpoints any more', function () {
        $passport = flightPassport(flightCleared());
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/flight/start", ['step_date' => '2026-04-20'])->assertNotFound();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/flight/result", ['result' => 'completed'])->assertNotFound();
    });
});

describe('roles', function () {
    it('lets data entry, admin and super admin book, confirm and undo', function (string $role) {
        $passport = flightPassport(flightCleared());
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->postJson(flightUrl($passport, 'booking'), ['booking_date' => '2026-06-15'])->assertCreated();
        $this->postJson(flightUrl($passport, 'confirm'))->assertOk();
        $this->deleteJson(flightUrl($passport, 'confirm'))->assertOk();
        $this->deleteJson(flightUrl($passport, 'booking'))->assertOk();
        $this->getJson('/api/data-entry/steps/flight/passports')->assertOk();
    })->with(['dataEntry', 'admin', 'superAdmin']);

    it('lets accounts read the Flight list but not act', function () {
        $passport = flightPassport(flightCleared(['flight' => StepStatus::InProcess]));
        $confirmed = flightPassport(flightCleared(['flight' => StepStatus::Completed]));
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->getJson('/api/data-entry/steps/flight/passports')->assertOk();
        $this->postJson(flightUrl($passport, 'booking'), ['booking_date' => '2026-06-15'])->assertForbidden();
        $this->deleteJson(flightUrl($passport, 'booking'))->assertForbidden();
        $this->postJson(flightUrl($passport, 'confirm'))->assertForbidden();
        $this->deleteJson(flightUrl($confirmed, 'confirm'))->assertForbidden();

        expect($passport->fresh()->current_stage)->toBe('flight')
            ->and($confirmed->fresh()->current_stage)->toBe('completed');
    });

    it('needs a login', function () {
        $passport = flightPassport(flightCleared());

        $this->postJson(flightUrl($passport, 'booking'), ['booking_date' => '2026-06-15'])->assertUnauthorized();
        $this->deleteJson(flightUrl($passport, 'booking'))->assertUnauthorized();
        $this->postJson(flightUrl($passport, 'confirm'))->assertUnauthorized();
        $this->deleteJson(flightUrl($passport, 'confirm'))->assertUnauthorized();
    });
});
