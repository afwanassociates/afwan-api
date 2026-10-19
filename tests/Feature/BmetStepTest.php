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
 * A passport with the visa issued on 2026-04-05 (valid until 2026-10-05),
 * plus a BMET record with the given status and details when $bmet is set.
 *
 * @param  array<string, mixed>  $details
 */
function bmetPassport(?StepStatus $bmet = null, array $details = []): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create(['passport_expiry_date' => '2035-01-01', 'entry_mode' => 'online']);
    MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
    PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
        ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);
    PassportStepRecord::factory()->for($passport)->step('visa')->on('2026-04-05')
        ->create(['details' => ['issue_date' => '2026-04-05'], 'valid_until' => '2026-10-05']);

    if ($bmet !== null) {
        PassportStepRecord::factory()->for($passport)->step('bmet')->status($bmet)->on('2026-04-10')->create(['reference_no' => null, 'details' => $details ?: null]);
    }

    return $passport->fresh();
}

function bmetUrl(PassportEntry $passport, string $action): string
{
    return "/api/data-entry/passports/{$passport->id}/steps/bmet/{$action}";
}

/**
 * @return list<int>
 */
function bmetTab(string $tab, string $step = 'bmet'): array
{
    return test()->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}&per_page=100")->assertOk()->json('data.*.id');
}

describe('milestones', function () {
    it('marks training and finger in any order, then the clearance', function (array $order) {
        $passport = bmetPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        // The first milestone creates the in-progress record.
        $this->postJson(bmetUrl($passport, $order[0]), ['date' => '2026-04-20'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_process')
            ->assertJsonPath('data.step_date', '2026-04-20')
            ->assertJsonPath('passport.current_stage', 'bmet')
            ->assertJsonPath('passport.stage_status', 'in_process');

        // Flight Pending all along, BMET cleared or not.
        expect(bmetTab('pending'))->toBe([$passport->id])
            ->and(bmetTab('pending', 'flight'))->toBe([$passport->id]);

        $this->postJson(bmetUrl($passport, $order[1]), ['date' => '2026-04-22'])
            ->assertOk()
            ->assertJsonPath('data.details.'.($order[0] === 'training' ? 'training_certificate_date' : 'finger_date'), '2026-04-20')
            ->assertJsonPath('data.details.'.($order[1] === 'training' ? 'training_certificate_date' : 'finger_date'), '2026-04-22');

        $this->postJson(bmetUrl($passport, 'clearance'), ['date' => '2026-04-25'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.status_label', 'BMET cleared')
            ->assertJsonPath('data.step_date', '2026-04-25')
            ->assertJsonPath('data.details.start_date', '2026-04-20')
            ->assertJsonPath('passport.current_stage', 'flight')
            ->assertJsonPath('passport.stage_status', 'waiting');

        expect(PassportStepRecord::query()->where('step_key', 'bmet')->count())->toBe(1)
            ->and(bmetTab('cleared'))->toBe([$passport->id])
            ->and(bmetTab('pending'))->toBe([])
            ->and(bmetTab('pending', 'flight'))->toBe([$passport->id])
            ->and($this->getJson('/api/data-entry/steps/flight/passports?tab=pending')->json('data.0'))->toMatchArray(['bmet_cleared' => true, 'clearance_date' => '2026-04-25', 'missing' => ['booking_date']])
            ->and($this->getJson('/api/data-entry/passports?view=overview&medical_status=all')->json('data.0.latest_status'))
            ->toMatchArray(['stage' => 'flight', 'status' => 'waiting', 'text' => 'BMET cleared - flight booking date pending', 'date' => '2026-04-25']);
    })->with([
        'training first' => [['training', 'finger']],
        'finger first' => [['finger', 'training']],
    ]);

    it('defaults the date to today in Dhaka and refuses a future date', function () {
        $passport = bmetPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson(bmetUrl($passport, 'training'))
            ->assertCreated()
            ->assertJsonPath('data.details.training_certificate_date', '2026-05-01');

        $this->postJson(bmetUrl($passport, 'finger'), ['date' => '2026-05-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'The date cannot be in the future.']);
        $this->postJson(bmetUrl($passport, 'finger'), ['date' => '01/05/2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    });

    it('refuses the clearance until training and finger are both done', function (?array $details, string $message) {
        $passport = bmetPassport($details === null ? null : StepStatus::InProcess, $details ?? []);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson(bmetUrl($passport, 'clearance'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'milestones_incomplete')
            ->assertJsonPath('message', $message);

        expect(bmetTab('cleared'))->toBe([]);
    })->with([
        'nothing done' => [null, 'Mark Training done and Finger done before BMET clearance done.'],
        'training only' => [['training_certificate_date' => '2026-04-10'], 'Mark Finger done before BMET clearance done.'],
        'finger only' => [['finger_date' => '2026-04-10'], 'Mark Training done before BMET clearance done.'],
        'an older submitted record' => [['start_date' => '2026-04-10'], 'Mark Training done and Finger done before BMET clearance done.'],
    ]);

    it('refuses a clearance dated before training or finger', function () {
        $passport = bmetPassport(StepStatus::InProcess, ['training_certificate_date' => '2026-04-10', 'finger_date' => '2026-04-18']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson(bmetUrl($passport, 'clearance'), ['date' => '2026-04-17'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date' => 'The bmet clearance done date cannot be before the training or finger date (18-04-2026).']);
    });

    it('needs the visa issued first', function () {
        $passport = bmetPassport();
        PassportStepRecord::query()->where('step_key', 'visa')->update(['status' => 'in_process']);
        app(WorkflowService::class)->recompute($passport);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson(bmetUrl($passport, 'training'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'previous_step_incomplete');
    });

    it('refuses a milestone once BMET is cleared', function (string $action) {
        $passport = bmetPassport(StepStatus::Completed, ['training_certificate_date' => '2026-04-08', 'finger_date' => '2026-04-09']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson(bmetUrl($passport, $action))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_already_completed');
    })->with(['training', 'finger', 'clearance']);

    it('keeps an older submitted BMET record in Pending and completes it with the milestones', function () {
        $passport = bmetPassport(StepStatus::InProcess);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(bmetTab('pending'))->toBe([$passport->id]);

        $this->postJson(bmetUrl($passport, 'training'), ['date' => '2026-04-20'])->assertOk()->assertJsonPath('data.step_date', '2026-04-10');
        $this->postJson(bmetUrl($passport, 'finger'), ['date' => '2026-04-21'])->assertOk();
        $this->postJson(bmetUrl($passport, 'clearance'), ['date' => '2026-04-22'])->assertOk()->assertJsonPath('data.details.start_date', '2026-04-10');

        expect(PassportStepRecord::query()->where('step_key', 'bmet')->count())->toBe(1)
            ->and(bmetTab('cleared'))->toBe([$passport->id]);
    });
});

describe('undo', function () {
    // Undo is for admin and super admin only (see the roles tests).
    it('undoes a milestone; undoing the last one removes the record', function () {
        $passport = bmetPassport();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson(bmetUrl($passport, 'training'), ['date' => '2026-04-20'])->assertCreated();
        $this->postJson(bmetUrl($passport, 'finger'), ['date' => '2026-04-21'])->assertOk();

        $this->deleteJson(bmetUrl($passport, 'finger'))
            ->assertOk()
            ->assertJsonPath('data.details', ['training_certificate_date' => '2026-04-20']);

        $this->deleteJson(bmetUrl($passport, 'training'))
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('passport.current_stage', 'bmet')
            ->assertJsonPath('passport.stage_status', 'waiting');

        expect(PassportStepRecord::query()->where('step_key', 'bmet')->count())->toBe(0)
            // Kept in the history.
            ->and(PassportStepRecord::withTrashed()->where('step_key', 'bmet')->sole()->deleted_by)->not->toBeNull()
            ->and(bmetTab('pending'))->toBe([$passport->id]);
    });

    it('refuses to undo a milestone that is not marked', function () {
        $passport = bmetPassport(StepStatus::InProcess, ['training_certificate_date' => '2026-04-10']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson(bmetUrl($passport, 'finger'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'milestone_not_marked');
        $this->deleteJson(bmetUrl(bmetPassport(), 'training'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'milestone_not_marked');
    });

    it('refuses to undo training or finger once BMET is cleared', function (string $action) {
        $passport = bmetPassport(StepStatus::Completed, ['training_certificate_date' => '2026-04-08', 'finger_date' => '2026-04-09']);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson(bmetUrl($passport, $action))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'milestone_locked');
    })->with(['training', 'finger']);

    it('undoes the clearance back to Pending with its milestones', function () {
        $passport = bmetPassport();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson(bmetUrl($passport, 'training'), ['date' => '2026-04-20']);
        $this->postJson(bmetUrl($passport, 'finger'), ['date' => '2026-04-21']);
        $this->postJson(bmetUrl($passport, 'clearance'), ['date' => '2026-04-22'])->assertOk();

        $this->deleteJson(bmetUrl($passport, 'clearance'))
            ->assertOk()
            ->assertJsonPath('data.status', 'in_process')
            ->assertJsonPath('data.step_date', '2026-04-20')
            ->assertJsonPath('data.details.training_certificate_date', '2026-04-20')
            ->assertJsonPath('data.details.finger_date', '2026-04-21')
            ->assertJsonMissingPath('data.details.start_date')
            ->assertJsonPath('passport.current_stage', 'bmet');

        expect(bmetTab('pending'))->toBe([$passport->id])
            ->and(bmetTab('pending', 'flight'))->toBe([$passport->id])
            ->and($this->getJson('/api/data-entry/steps/flight/passports?tab=pending')->json('data.0.missing'))->toBe(['bmet', 'booking_date']);

        $this->deleteJson(bmetUrl($passport, 'clearance'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_not_completed');
    });

    it('refuses to undo the clearance once the flight is confirmed', function () {
        $passport = bmetPassport(StepStatus::Completed, ['training_certificate_date' => '2026-04-08', 'finger_date' => '2026-04-09']);
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/flight/booking", ['booking_date' => '2026-05-20'])->assertCreated();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/flight/confirm")->assertOk();

        $this->deleteJson(bmetUrl($passport, 'clearance'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'later_step_completed')
            ->assertJsonPath('message', 'Undo the Flight step first.');

        // After undoing the flight confirmation it can be undone; the booking stays.
        $this->deleteJson("/api/data-entry/passports/{$passport->id}/steps/flight/confirm")->assertOk();
        $this->deleteJson(bmetUrl($passport, 'clearance'))->assertOk()->assertJsonPath('passport.current_stage', 'bmet');

        expect(bmetTab('pending', 'flight'))->toBe([$passport->id])
            ->and($this->getJson('/api/data-entry/steps/flight/passports?tab=pending')->json('data.0'))->toMatchArray(['booking_date' => '2026-05-20', 'missing' => ['bmet']]);
    });
});

describe('tabs', function () {
    it('has only the Pending and BMET cleared tabs', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/workflow/config')
            ->assertJsonPath('data.steps.4.tabs', [['key' => 'pending', 'label' => 'Pending'], ['key' => 'cleared', 'label' => 'BMET cleared']])
            ->assertJsonPath('data.steps.4.statuses', [['value' => 'in_process', 'label' => 'In progress'], ['value' => 'completed', 'label' => 'BMET cleared']])
            ->assertJsonPath('data.steps.4.actions.*.label', ['Training done', 'Finger done', 'BMET clearance done']);

        expect(array_keys($this->getJson('/api/data-entry/steps/bmet/passports')->json('tab_counts')))->toBe(['pending', 'cleared']);

        foreach (['submitted', 'in_process', 'completed', 'rejected', 'waiting'] as $tab) {
            $this->getJson("/api/data-entry/steps/bmet/passports?tab={$tab}")->assertUnprocessable()->assertJsonValidationErrors('tab');
        }
    });

    it('puts every state in the right tab, with the same counts in the list, tab counts and summary', function () {
        $waiting = bmetPassport();
        $inProgress = bmetPassport(StepStatus::InProcess, ['training_certificate_date' => '2026-04-10']);
        // Rejected before: history only, back to Pending.
        $rejected = bmetPassport(StepStatus::Rejected);
        $cleared = bmetPassport(StepStatus::Completed, ['training_certificate_date' => '2026-04-08', 'finger_date' => '2026-04-09']);
        bmetPassport()->delete();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $counts = $this->getJson('/api/data-entry/steps/bmet/passports')->json('tab_counts');
        $summary = $this->getJson('/api/data-entry/workflow/summary')->json('data');

        expect(bmetTab('pending'))->toEqualCanonicalizing([$waiting->id, $inProgress->id, $rejected->id])
            ->and(bmetTab('cleared'))->toBe([$cleared->id])
            ->and($counts)->toBe(['pending' => 3, 'cleared' => 1])
            ->and($summary['step_tabs']['bmet'])->toBe($counts)
            ->and($summary['stages']['bmet'])->toBe(['total' => 3, 'waiting' => 3])
            // Flight Pending: every issued visa, BMET cleared or not.
            ->and($summary['step_tabs']['flight'])->toBe(['pending' => 4, 'confirmed' => 0])
            ->and($summary['stages']['flight'])->toBe(['total' => 4, 'waiting' => 4])
            ->and(PassportStepRecord::query()->where('step_key', 'bmet')->where('status', 'rejected')->exists())->toBeTrue();
    });

    it('returns the BMET row fields', function () {
        $passport = bmetPassport(StepStatus::InProcess, ['training_certificate_date' => '2026-04-10']);
        $cleared = bmetPassport(StepStatus::Completed, ['training_certificate_date' => '2026-04-08', 'finger_date' => '2026-04-09']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect($this->getJson('/api/data-entry/steps/bmet/passports?tab=pending')->json('data.0'))->toMatchArray([
            'id' => $passport->id,
            'passport_name' => $passport->passport_name,
            'passport_number' => $passport->passport_number,
            'visa_issue_date' => '2026-04-05',
            'visa_valid_until' => '2026-10-05',
            'training_date' => '2026-04-10',
            'finger_date' => null,
            'clearance_date' => null,
        ])->and($this->getJson('/api/data-entry/steps/bmet/passports?tab=cleared')->json('data.0'))->toMatchArray([
            'id' => $cleared->id,
            'training_date' => '2026-04-08',
            'finger_date' => '2026-04-09',
            'clearance_date' => '2026-04-10',
        ])->and(collect($this->getJson('/api/data-entry/steps/flight/passports?tab=pending')->json('data'))->firstWhere('id', $cleared->id))->toMatchArray([
            'visa_valid_until' => '2026-10-05',
            'bmet_cleared' => true,
            'clearance_date' => '2026-04-10',
            'booking_date' => null,
        ]);
    });
});

describe('roles', function () {
    it('lets data entry, admin and super admin mark; only admin and super admin undo', function (string $role, bool $canUndo) {
        $passport = bmetPassport();
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $this->postJson(bmetUrl($passport, 'training'))->assertCreated();
        $this->postJson(bmetUrl($passport, 'finger'))->assertOk();
        $this->postJson(bmetUrl($passport, 'clearance'))->assertOk();
        $this->deleteJson(bmetUrl($passport, 'clearance'))->assertStatus($canUndo ? 200 : 403);
        $this->deleteJson(bmetUrl($passport, 'finger'))->assertStatus($canUndo ? 200 : 403);
    })->with([['dataEntry', false], ['admin', true], ['superAdmin', true]]);

    it('lets accounts read the BMET list but not act', function () {
        $passport = bmetPassport(StepStatus::InProcess, ['training_certificate_date' => '2026-04-10']);
        Sanctum::actingAs(User::factory()->accounts()->create());

        $this->getJson('/api/data-entry/steps/bmet/passports')->assertOk();

        foreach (['training', 'finger', 'clearance'] as $action) {
            $this->postJson(bmetUrl($passport, $action))->assertForbidden();
            $this->deleteJson(bmetUrl($passport, $action))->assertForbidden();
        }

        expect(PassportStepRecord::query()->where('step_key', 'bmet')->sole()->details)->toBe(['training_certificate_date' => '2026-04-10']);
    });

    it('needs a login', function () {
        $passport = bmetPassport();

        $this->postJson(bmetUrl($passport, 'training'))->assertUnauthorized();
        $this->deleteJson(bmetUrl($passport, 'training'))->assertUnauthorized();
    });

    it('has no other BMET actions', function () {
        $passport = bmetPassport();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson(bmetUrl($passport, 'submitted'))->assertNotFound();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/training")->assertNotFound();
    });
});
