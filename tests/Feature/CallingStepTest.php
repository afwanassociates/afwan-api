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
 * A passport with a fit medical issued on $fitOn (valid until 2026-07-01 by
 * default), marked Online unless $mode is null.
 */
function callingPassport(?string $mode = 'online', string $fitOn = '2026-04-01', string $validUntil = '2026-07-01'): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create([
        'passport_expiry_date' => '2035-01-01',
        'entry_mode' => $mode,
    ]);
    MedicalRecord::factory()->for($passport)->on($fitOn)->create(['issue_date' => $fitOn, 'valid_until' => $validUntil]);

    return $passport->fresh();
}

/**
 * A step record for the passport.
 *
 * @param  array<string, mixed>  $attributes
 */
function stepRecord(PassportEntry $passport, string $step, StepStatus $status, string $date = '2026-04-10', array $attributes = []): PassportStepRecord
{
    return PassportStepRecord::factory()->for($passport)->step($step)->status($status)->on($date)
        ->create(['reference_no' => null, ...$attributes]);
}

/**
 * A passport whose calling is permitted (received 2026-04-12, valid until $validUntil).
 */
function permittedPassport(string $validUntil = '2026-10-11', string $receivedOn = '2026-04-12'): PassportEntry
{
    $passport = callingPassport();
    stepRecord($passport, 'calling', StepStatus::Completed, '2026-04-10', [
        'details' => ['receive_date' => $receivedOn, 'issue_date' => '2026-04-11'],
        'valid_until' => $validUntil,
    ]);

    return $passport->fresh();
}

/**
 * IDs in a step tab.
 *
 * @return list<int>
 */
function tabIds(string $step, string $tab, string $query = ''): array
{
    return test()->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}&per_page=100&{$query}")->assertOk()->json('data.*.id');
}

/**
 * A calling update body: the three permit dates.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function callingUpdate(array $overrides = []): array
{
    return ['receive_date' => '2026-04-26', 'issue_date' => '2026-04-25', 'valid_until' => '2026-10-25', ...$overrides];
}

describe('calling tabs', function () {
    it('has Pending (no record or a calling date entered) and Permitted (no visa yet)', function () {
        $ready = callingPassport();
        $dated = callingPassport();
        stepRecord($dated, 'calling', StepStatus::InProcess);
        $permitted = permittedPassport();
        // Permitted, then the visa submitted date entered: off Permitted.
        $visaSubmitted = permittedPassport();
        stepRecord($visaSubmitted, 'visa', StepStatus::InProcess, '2026-04-20');
        $visaRejected = permittedPassport();
        stepRecord($visaRejected, 'visa', StepStatus::Rejected, '2026-04-20');
        $visaIssued = permittedPassport();
        stepRecord($visaIssued, 'visa', StepStatus::Completed, '2026-04-20');
        // Not in Calling: not marked, medical run out, unfit.
        callingPassport(null);
        callingPassport('online', '2026-01-20', '2026-04-30');
        tap(PassportEntry::factory()->withMedicalSlip()->create(), fn ($p) => MedicalRecord::factory()->for($p)->unfit()->create());
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(tabIds('calling', 'pending'))->toEqualCanonicalizing([$ready->id, $dated->id])
            ->and(tabIds('calling', 'permitted'))->toBe([$permitted->id])
            ->and($this->getJson('/api/data-entry/steps/calling/passports')->json('data.*.id'))->toEqualCanonicalizing([$ready->id, $dated->id])
            ->and($this->getJson('/api/data-entry/steps/calling/passports?tab=permitted')->json('tab_counts'))->toBe(['pending' => 2, 'permitted' => 1]);
    });

    it('rejects tabs the step does not have', function (string $step, string $tab) {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tab');
    })->with([
        ['calling', 'in_process'],
        ['calling', 'completed'],
        ['calling', 'rejected'],
        ['visa', 'in_process'],
        ['visa', 'completed'],
        ['visa', 'permitted'],
    ]);

    it('treats an old rejected calling record as history: back to Pending without a calling date', function () {
        $passport = callingPassport();
        stepRecord($passport, 'calling', StepStatus::Rejected, '2026-04-08');
        app(WorkflowService::class)->recompute($passport->fresh());
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect($this->getJson('/api/data-entry/steps/calling/passports?tab=pending')->json('data.0'))
            ->toMatchArray(['id' => $passport->id, 'record' => null]);
    });

    it('returns the calling dates on Permitted rows', function () {
        $passport = permittedPassport();
        Sanctum::actingAs(User::factory()->accounts()->create());

        $row = $this->getJson('/api/data-entry/steps/calling/passports?tab=permitted')->json('data.0');

        expect($row['record'])->toMatchArray([
            'status' => 'completed',
            'status_label' => 'Permitted',
            'receive_date' => '2026-04-12',
            'issue_date' => '2026-04-11',
            'valid_until' => '2026-10-11',
        ])
            ->and($row['next_record'])->toBeNull()
            ->and($row['previous_step_completed_on'])->toBe('2026-04-01')
            ->and($row)->toHaveKeys(['reference', 'company'])
            // No mode and no calling date on this list.
            ->and($row)->not->toHaveKey('entry_mode')
            ->and($row['record'])->not->toHaveKeys(['step_date', 'submitted_date']);
    });

    it('orders Pending by the oldest fit medical and Permitted by the newest permit', function () {
        $fitMiddle = callingPassport('online', '2026-04-10');
        $fitOldest = callingPassport('online', '2026-03-20');
        $fitNewest = callingPassport('online', '2026-04-20');
        stepRecord($fitNewest, 'calling', StepStatus::InProcess, '2026-04-25');
        $permitOld = permittedPassport(receivedOn: '2026-04-11');
        $permitNew = permittedPassport(receivedOn: '2026-04-20');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(tabIds('calling', 'pending'))->toBe([$fitOldest->id, $fitMiddle->id, $fitNewest->id])
            ->and(tabIds('calling', 'pending', 'sort=created_at&direction=desc'))->toBe([$fitOldest->id, $fitMiddle->id, $fitNewest->id])
            ->and(tabIds('calling', 'permitted'))->toBe([$permitNew->id, $permitOld->id]);
    });
});

describe('calling update', function () {
    it('saves the three permit dates in one request: Permitted', function () {
        $passport = callingPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect($this->getJson("/api/data-entry/passports/{$passport->id}")->json('data.latest_status.text'))->toBe('Ready for Calling (Online)');

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", callingUpdate(['step_date' => '2020-01-01']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.status_label', 'Permitted')
            // The record's date is the receive date (a calling date is ignored).
            ->assertJsonPath('data.step_date', '2026-04-26')
            ->assertJsonPath('data.details', ['receive_date' => '2026-04-26', 'issue_date' => '2026-04-25'])
            ->assertJsonPath('data.valid_until', '2026-10-25')
            ->assertJsonPath('passport.current_stage', 'visa')
            ->assertJsonPath('passport.stage_status', 'waiting')
            ->assertJsonStructure(['warnings']);

        expect(PassportStepRecord::count())->toBe(1)
            ->and(tabIds('calling', 'pending'))->toBe([])
            ->and(tabIds('calling', 'permitted'))->toBe([$passport->id])
            ->and($this->getJson("/api/data-entry/passports/{$passport->id}")->json('data.latest_status.text'))->toBe('Permitted - enter visa submitted date');

        // A permitted calling can no longer change.
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", callingUpdate(['receive_date' => '2026-04-27']))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'step_already_completed');
    });

    it('completes an older calling-date record, keeping its calling date in the history', function () {
        // Started before marks existed: no Online mark.
        $passport = callingPassport(null);
        $legacy = stepRecord($passport, 'calling', StepStatus::InProcess, '2026-04-15');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(tabIds('calling', 'pending'))->toBe([$passport->id]);

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", callingUpdate())
            ->assertOk()
            ->assertJsonPath('data.id', $legacy->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.step_date', '2026-04-26')
            ->assertJsonPath('data.details.calling_date', '2026-04-15')
            ->assertJsonPath('data.details.receive_date', '2026-04-26');

        expect(PassportStepRecord::count())->toBe(1)
            ->and(tabIds('calling', 'permitted'))->toBe([$passport->id]);
    });

    it('requires all three dates', function (string $missing, string $message) {
        $passport = callingPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $body = callingUpdate();
        unset($body[$missing]);

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$missing => $message]);

        expect(PassportStepRecord::count())->toBe(0);
    })->with([
        ['receive_date', 'Enter the calling receive date.'],
        ['issue_date', 'Enter the issued date.'],
        ['valid_until', 'Enter the expiry date.'],
    ]);

    it('checks the dates', function (array $body, string $field, string $message) {
        $passport = callingPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);
    })->with([
        // 2 May is tomorrow in Dhaka.
        'received in the future' => [callingUpdate(['receive_date' => '2026-05-02']), 'receive_date', 'The calling receive date cannot be in the future.'],
        'issued in the future' => [callingUpdate(['issue_date' => '2026-05-02', 'valid_until' => '2026-11-02']), 'issue_date', 'The issued date cannot be in the future.'],
        'expiry on the issued date' => [callingUpdate(['valid_until' => '2026-04-25']), 'valid_until', 'The expiry date must be after the issued date.'],
        'expiry before the issued date' => [callingUpdate(['valid_until' => '2026-04-24']), 'valid_until', 'The expiry date must be after the issued date.'],
        'bad date' => [callingUpdate(['receive_date' => '26/04/2026']), 'receive_date', 'format'],
    ]);

    it('accepts today in Dhaka', function () {
        $passport = callingPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", callingUpdate([
            'receive_date' => '2026-05-01', 'issue_date' => '2026-05-01', 'valid_until' => '2026-11-01',
        ]))->assertCreated();
    });

    it('needs a valid fit medical and the Online mark', function (Closure $setUp, string $code) {
        $passport = $setUp();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", callingUpdate())
            ->assertUnprocessable()
            ->assertJsonPath('code', $code);

        expect(PassportStepRecord::count())->toBe(0);
    })->with([
        'not marked' => [fn () => callingPassport(null), 'entry_mode_missing'],
        'medical expired' => [fn () => callingPassport('online', '2026-01-20', '2026-04-30'), 'medical_not_valid'],
    ]);

    it('warns when the permit expires soon', function () {
        $passport = callingPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", callingUpdate(['valid_until' => '2026-05-20']))
            ->assertCreated()
            ->assertJsonPath('warnings.*.code', ['permit_expires_soon']);
    });

    it('no longer has calling start and result endpoints', function () {
        $passport = callingPassport();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/start", ['step_date' => '2026-04-20'])->assertNotFound();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/result", callingUpdate())->assertNotFound();
    });

    it('refuses rejected and new in-process calling records through the older endpoints', function () {
        $passport = callingPassport();
        $record = stepRecord($passport, 'calling', StepStatus::InProcess, '2026-04-20');
        Sanctum::actingAs(User::factory()->admin()->create());

        foreach (['rejected', 'in_process'] as $status) {
            $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling", ['status' => $status])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['status' => 'This status is not available for this step.']);
        }
        $this->patchJson("/api/data-entry/step-records/{$record->id}", ['status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        // An older in-process record can still be edited (e.g. its remarks).
        $this->patchJson("/api/data-entry/step-records/{$record->id}", ['remarks' => 'Checked'])->assertOk();
    });
});

describe('visa', function () {
    it('starts from the Permitted tab: the visa submitted date moves it to Visa Pending', function () {
        $passport = permittedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", ['step_date' => '2026-04-20'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_process')
            ->assertJsonPath('passport.current_stage', 'visa')
            ->assertJsonPath('passport.stage_status', 'in_process')
            ->assertJsonPath('warnings', []);

        expect(tabIds('calling', 'permitted'))->toBe([])
            ->and(tabIds('visa', 'pending'))->toBe([$passport->id])
            ->and($this->getJson("/api/data-entry/passports/{$passport->id}")->json('data.latest_status.text'))->toBe('Visa submitted');

        $row = $this->getJson('/api/data-entry/steps/visa/passports?tab=pending')->json('data.0');

        expect($row['record'])->toMatchArray(['status' => 'in_process', 'submitted_date' => '2026-04-20'])
            ->and($row['previous_record'])->toMatchArray(['step_date' => '2026-04-10', 'receive_date' => '2026-04-12', 'issue_date' => '2026-04-11', 'valid_until' => '2026-10-11']);
    });

    it('warns, without blocking, about a visa before the permit was received and an expired permit', function (Closure $setUp, string $visaDate, array $codes) {
        $passport = $setUp();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/visa/start", ['step_date' => $visaDate])
            ->assertCreated()
            ->assertJsonPath('warnings.*.code', $codes);
    })->with([
        'visa before the calling receive date' => [fn () => permittedPassport(), '2026-04-11', ['visa_before_calling_received']],
        'calling permit expired' => [fn () => permittedPassport('2026-04-25'), '2026-04-20', ['permit_expired']],
        'both' => [fn () => permittedPassport('2026-04-25'), '2026-04-11', ['permit_expired', 'visa_before_calling_received']],
    ]);

    it('needs the calling permitted, and a visa date not in the future', function () {
        $dated = callingPassport();
        stepRecord($dated, 'calling', StepStatus::InProcess, '2026-04-20');
        $permitted = permittedPassport();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/data-entry/passports/{$dated->id}/steps/visa/start", ['step_date' => '2026-04-25'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'previous_step_incomplete');
        $this->postJson("/api/data-entry/passports/{$permitted->id}/steps/visa/start", ['step_date' => '2026-05-02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('step_date');
        $this->postJson("/api/data-entry/passports/{$permitted->id}/steps/visa/start", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('step_date');
    });

    it('has Pending (submitted), Visa issued and Rejected tabs, and Apply again', function () {
        $submitted = permittedPassport();
        stepRecord($submitted, 'visa', StepStatus::InProcess, '2026-04-20');
        $issued = permittedPassport();
        stepRecord($issued, 'visa', StepStatus::Completed, '2026-04-20', ['reference_no' => 'V-1']);
        $rejected = permittedPassport();
        stepRecord($rejected, 'visa', StepStatus::Rejected, '2026-04-20');
        // Permitted but no visa date yet: only under Calling's Permitted.
        $notStarted = permittedPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(tabIds('visa', 'pending'))->toBe([$submitted->id])
            ->and(tabIds('visa', 'issued'))->toBe([$issued->id])
            ->and(tabIds('visa', 'rejected'))->toBe([$rejected->id])
            ->and(tabIds('calling', 'permitted'))->toBe([$notStarted->id])
            ->and($this->getJson('/api/data-entry/steps/visa/passports')->json('tab_counts'))->toBe(['pending' => 1, 'issued' => 1, 'rejected' => 1]);

        // The visa result keeps its endpoint and labels.
        $this->postJson("/api/data-entry/passports/{$submitted->id}/steps/visa/result", ['result' => 'completed', 'issue_date' => '2026-04-25', 'valid_until' => '2026-10-25'])
            ->assertOk()
            ->assertJsonPath('data.status_label', 'Visa issued');

        // Apply again: a new in-process record, back in Visa Pending.
        $this->postJson("/api/data-entry/passports/{$rejected->id}/steps/visa/start", ['step_date' => '2026-04-28'])->assertCreated();

        expect(tabIds('visa', 'pending'))->toBe([$rejected->id])
            ->and(tabIds('visa', 'issued'))->toEqualCanonicalizing([$issued->id, $submitted->id])
            ->and(tabIds('visa', 'rejected'))->toBe([])
            ->and(PassportStepRecord::query()->where('passport_entry_id', $rejected->id)->where('step_key', 'visa')->count())->toBe(2);
    });
});

describe('summary and roles', function () {
    it('counts the Calling and Visa tabs exactly as the lists do', function () {
        callingPassport();
        stepRecord(callingPassport(), 'calling', StepStatus::InProcess);
        permittedPassport();
        permittedPassport();
        stepRecord(permittedPassport(), 'visa', StepStatus::InProcess, '2026-04-20');
        stepRecord(permittedPassport(), 'visa', StepStatus::Completed, '2026-04-20');
        stepRecord(permittedPassport(), 'visa', StepStatus::Rejected, '2026-04-20');
        callingPassport(null);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $tabs = $this->getJson('/api/data-entry/workflow/summary')->json('data.step_tabs');

        expect($tabs['calling'])->toBe(['pending' => 2, 'permitted' => 2])
            ->and($tabs['visa'])->toBe(['pending' => 1, 'issued' => 1, 'rejected' => 1]);

        foreach (['calling', 'visa'] as $step) {
            $list = $this->getJson("/api/data-entry/steps/{$step}/passports?per_page=1")->json('tab_counts');

            expect($list)->toBe($tabs[$step]);

            foreach (array_keys($tabs[$step]) as $tab) {
                expect($tabs[$step][$tab])->toBe($this->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}&per_page=1")->json('meta.total'));
            }
        }
    });

    it('lets data entry, admin and super admin act, accounts only read', function () {
        $passport = callingPassport();
        $permitted = permittedPassport();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson('/api/data-entry/steps/calling/passports?tab=pending')->assertOk();
        $this->getJson('/api/data-entry/steps/visa/passports?tab=pending')->assertOk();
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", callingUpdate())->assertForbidden();
        $this->postJson("/api/data-entry/passports/{$permitted->id}/steps/visa/start", ['step_date' => '2026-04-20'])->assertForbidden();

        expect(PassportStepRecord::query()->where('passport_entry_id', $passport->id)->exists())->toBeFalse();

        foreach (['dataEntry', 'admin', 'superAdmin'] as $role) {
            $other = callingPassport();
            Sanctum::actingAs(User::factory()->{$role}()->create());
            $this->postJson("/api/data-entry/passports/{$other->id}/steps/calling/update", callingUpdate())->assertCreated();
            $this->postJson("/api/data-entry/passports/{$other->id}/steps/visa/start", ['step_date' => '2026-04-27'])->assertCreated();
        }
    });

    it('rejects guests', function () {
        $passport = callingPassport();

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", callingUpdate())->assertUnauthorized();
    });
});
