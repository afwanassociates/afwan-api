<?php

use App\Enums\StepStatus;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Today in Dhaka is 2026-05-01 (00:30), while UTC is still on 2026-04-30.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 00:30', 'Asia/Dhaka')->utc());
});

/**
 * A passport with its slip and medical date entered, ready for a result.
 *
 * @param  array<string, mixed>  $attributes
 */
function readyForResult(array $attributes = []): PassportEntry
{
    return PassportEntry::factory()->withMedicalSlip('2026-04-10')->create([
        'medical_date' => '2026-04-20',
        'passport_expiry_date' => '2035-01-01',
        ...$attributes,
    ]);
}

/**
 * A passport with a fit medical (factory), in the given state: "fit" (valid
 * until 2026-07-01), "expiring_soon" (2026-05-10) or "expired" (yesterday in
 * Dhaka). Not marked unless $mode is given.
 */
function fitPassport(string $state = 'fit', ?string $mode = null): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create([
        'passport_expiry_date' => '2035-01-01',
        'entry_mode' => $mode,
    ]);

    MedicalRecord::factory()->for($passport)->validUntil(match ($state) {
        'fit' => '2026-07-01',
        'expiring_soon' => '2026-05-10',
        'expired' => '2026-04-30',
    })->create();

    return $passport->fresh();
}

/**
 * IDs in a list response.
 *
 * @return list<int>
 */
function idsOf(string $url): array
{
    return test()->getJson($url)->assertOk()->json('data.*.id');
}

describe('fit result dates', function () {
    it('stores the entered issue and expiry dates, nothing computed', function () {
        $passport = readyForResult();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", [
            'result' => 'fit',
            'issue_date' => '2026-04-22',
            'expiry_date' => '2026-10-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.issue_date', '2026-04-22')
            ->assertJsonPath('data.valid_until', '2026-10-01')
            ->assertJsonPath('passport.medical_status', 'fit')
            // Fit, but not marked: it stays at Medical.
            ->assertJsonPath('passport.current_stage', 'medical')
            ->assertJsonPath('passport.stage_status', 'fit');

        expect(MedicalRecord::sole())
            ->issue_date->toDateString()->toBe('2026-04-22')
            ->valid_until->toDateString()->toBe('2026-10-01');
    });

    it('requires both dates for fit, in order, with clear messages', function (array $dates, string $field, string $message) {
        $passport = readyForResult();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'fit', ...$dates])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        expect(MedicalRecord::count())->toBe(0);
    })->with([
        'no issue date' => [['expiry_date' => '2026-07-20'], 'issue_date', 'Enter the issue date of the fit medical.'],
        'no expiry date' => [['issue_date' => '2026-04-20'], 'expiry_date', 'Enter the expiry date of the fit medical.'],
        // 2 May is tomorrow in Dhaka.
        'issue date in the future' => [['issue_date' => '2026-05-02', 'expiry_date' => '2026-08-02'], 'issue_date', 'The issue date cannot be in the future.'],
        'expiry on the issue date' => [['issue_date' => '2026-04-20', 'expiry_date' => '2026-04-20'], 'expiry_date', 'The expiry date must be after the issue date.'],
        'expiry before the issue date' => [['issue_date' => '2026-04-20', 'expiry_date' => '2026-04-19'], 'expiry_date', 'The expiry date must be after the issue date.'],
        'bad issue date' => [['issue_date' => '20/04/2026', 'expiry_date' => '2026-07-20'], 'issue_date', 'The issue date must be a date (YYYY-MM-DD).'],
    ]);

    it('accepts an issue date of today in Dhaka', function () {
        $passport = readyForResult();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'fit', 'issue_date' => '2026-05-01', 'expiry_date' => '2026-08-01'])
            ->assertCreated();
    });

    it('needs no dates for unfit, and ignores any sent', function () {
        $passport = readyForResult();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'unfit', 'issue_date' => 'nonsense', 'expiry_date' => '2026-01-01'])
            ->assertCreated()
            ->assertJsonPath('data.issue_date', null)
            ->assertJsonPath('data.valid_until', null)
            ->assertJsonPath('passport.medical_status', 'unfit');
    });

    it('computes expiring soon and expired from the entered expiry', function () {
        $passport = readyForResult();
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->postJson("/api/data-entry/passports/{$passport->id}/medical-result", ['result' => 'fit', 'issue_date' => '2026-04-20', 'expiry_date' => '2026-05-15'])->assertCreated();

        $status = fn () => $this->getJson("/api/data-entry/passports/{$passport->id}")->json('data.medical_status');

        // 14 days left (config expiring_soon_days).
        expect($status())->toBe('expiring_soon');
        Carbon::setTestNow(Carbon::parse('2026-05-16 00:30', 'Asia/Dhaka')->utc());
        expect($status())->toBe('expired');
    });

    it('offers the default validity for prefilling the expiry date', function () {
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson('/api/data-entry/defaults')
            ->assertOk()
            ->assertJsonPath('data.medical_validity_months', 3)
            ->assertJsonPath('data.medical_expiring_soon_days', 14);
    });
});

describe('Online mark', function () {
    it('makes a fit passport available for Calling only once marked', function () {
        $mode = 'online';
        $label = 'Online';
        $passport = fitPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(idsOf('/api/data-entry/steps/calling/passports'))->toBe([])
            ->and($this->getJson('/api/data-entry/workflow/summary')->json('data.step_tabs.calling.pending'))->toBe(0);

        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => $mode])
            ->assertOk()
            ->assertJsonPath('data.entry_mode', ['value' => $mode, 'label' => $label])
            ->assertJsonPath('data.current_stage', 'calling')
            ->assertJsonPath('data.stage_status', 'waiting');

        expect(idsOf('/api/data-entry/steps/calling/passports'))->toBe([$passport->id])
            ->and($this->getJson('/api/data-entry/workflow/summary')->json('data.step_tabs.calling.pending'))->toBe(1)
            // The Calling list no longer shows the mark (the passport still has it).
            ->and($this->getJson('/api/data-entry/steps/calling/passports')->json('data.0'))->not->toHaveKey('entry_mode')
            ->and($this->getJson("/api/data-entry/passports/{$passport->id}")->json('data.entry_mode'))->toBe(['value' => $mode, 'label' => $label]);

        // Clearing the mark is an undo, for the operations head only: it stays in Calling.
        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => null])->assertForbidden();

        expect(idsOf('/api/data-entry/steps/calling/passports'))->toBe([$passport->id]);
    });

    it('refuses to mark a passport without a valid fit medical', function (Closure $setUp) {
        $passport = $setUp();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entry_mode' => 'Only a passport with a fit medical that has not expired can be marked Online.']);

        expect($passport->fresh()->entry_mode)->toBeNull();
    })->with([
        'expired fit' => [fn () => fitPassport('expired')],
        'unfit' => [fn () => tap(PassportEntry::factory()->withMedicalSlip()->create(), fn ($p) => MedicalRecord::factory()->for($p)->unfit()->create())],
        'no result yet' => [fn () => PassportEntry::factory()->withMedicalSlip()->create()],
        'medical not started' => [fn () => PassportEntry::factory()->create()],
    ]);

    it('allows marking an expiring-soon fit passport', function () {
        $passport = fitPassport('expiring_soon');
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])->assertOk();
    });

    it('refuses changing or clearing the mark once Calling has a record', function (?string $newMode, int $status) {
        $passport = fitPassport('fit', 'online');
        PassportStepRecord::factory()->for($passport)->step('calling')->status(StepStatus::InProcess)->on('2026-04-25')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => $newMode])
            ->assertStatus($status);
        if ($status === 422) {
            $response->assertJsonValidationErrors(['entry_mode' => 'The Online mark cannot be changed once Calling / Work Permit has started.']);
        }

        expect($passport->fresh()->entry_mode->value)->toBe('online');
    })->with([
        'marked again: too late' => ['online', 422],
        // Clearing is an undo, for the operations head only.
        'cleared: not allowed' => [null, 403],
    ]);

    it('validates the mark', function (array $body) {
        $passport = fitPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entry_mode');
    })->with([
        'missing' => [[]],
        'unknown' => [['entry_mode' => 'offline']],
    ]);

    it('lets only data entry mark, not admins or anyone else', function () {
        $passport = fitPassport();

        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])->assertForbidden();

        foreach (['admin', 'superAdmin'] as $role) {
            Sanctum::actingAs(User::factory()->{$role}()->create());
            $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])->assertOk();
    });

    it('refuses to start Calling without the mark', function () {
        $passport = fitPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling/update", ['receive_date' => '2026-04-25', 'issue_date' => '2026-04-25', 'valid_until' => '2026-10-25'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'entry_mode_missing')
            ->assertJsonPath('message', 'Mark the passport Online before starting Calling / Work Permit.');
        // The older record-a-step endpoint applies the same rule.
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/calling", [
            'status' => 'completed',
            'details' => ['receive_date' => '2026-04-25', 'issue_date' => '2026-04-25'],
            'valid_until' => '2026-10-25',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'entry_mode_missing');

        expect(PassportStepRecord::count())->toBe(0);
    });

    it('keeps passports that started Calling before marks existed working', function () {
        $legacy = fitPassport();
        PassportStepRecord::factory()->for($legacy)->step('calling')->on('2026-04-02')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->getJson("/api/data-entry/passports/{$legacy->id}")
            ->assertJsonPath('data.entry_mode', null)
            ->assertJsonPath('data.has_calling_record', true)
            ->assertJsonPath('data.current_stage', 'visa');

        $this->postJson("/api/data-entry/passports/{$legacy->id}/steps/visa/start", ['step_date' => '2026-04-25'])->assertCreated();
    });

    it('lets data entry and the operations head mark, and nobody else', function () {
        $passport = fitPassport();

        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])->assertForbidden();

        foreach (['dataEntry', 'operationHead'] as $role) {
            Sanctum::actingAs(User::factory()->{$role}()->create());
            $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])->assertOk();
        }
    });
});

describe('medical list', function () {
    it('keeps only unmarked passports in the Fit and Expiring soon tabs', function (string $tab) {
        $state = $tab === 'fit' ? 'fit' : 'expiring_soon';
        $unmarked1 = fitPassport($state);
        $unmarked2 = fitPassport($state);
        fitPassport($state, 'online');
        fitPassport($state, 'online');
        // Calling started before marks existed: no mark, so still listed.
        $legacy = fitPassport($state);
        PassportStepRecord::factory()->for($legacy)->step('calling')->status(StepStatus::InProcess)->on('2026-04-25')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $url = "/api/data-entry/passports?mode=medical&medical_status={$tab}&per_page=100";
        $rows = collect($this->getJson($url)->assertOk()->json('data'))->keyBy('id');

        expect($rows->keys()->all())->toEqualCanonicalizing([$unmarked1->id, $unmarked2->id, $legacy->id])
            ->and($rows->pluck('entry_mode')->filter()->all())->toBe([])
            ->and($rows[$legacy->id]['has_calling_record'])->toBeTrue()
            ->and($rows[$unmarked1->id]['has_calling_record'])->toBeFalse()
            ->and($rows[$unmarked1->id])->toHaveKeys(['issue_date', 'valid_until', 'medical_slip_date'])
            ->and($rows[$unmarked1->id]['medical_slip_date'])->toBe('2026-01-10')
            // The mark filter and chip counts are gone: mark is ignored.
            ->and($this->getJson("{$url}&mark=online")->assertOk()->json('data.*.id'))->toEqualCanonicalizing($rows->keys()->all())
            ->and($this->getJson($url)->json())->not->toHaveKey('mark_counts');
    })->with(['fit', 'expiring_soon']);

    it('moves a passport from the Fit tab to Calling pending when marked, for good (data entry cannot clear it)', function (string $state, string $tab) {
        $passport = fitPassport($state);
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $fitTab = "/api/data-entry/passports?mode=medical&medical_status={$tab}";

        expect(idsOf($fitTab))->toBe([$passport->id])
            ->and(idsOf('/api/data-entry/steps/calling/passports'))->toBe([]);

        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])
            ->assertOk()
            ->assertJsonPath('data.entry_mode', ['value' => 'online', 'label' => 'Online'])
            ->assertJsonPath('data.latest_status.text', 'Ready for Calling (Online)');

        expect(idsOf($fitTab))->toBe([])
            ->and(idsOf('/api/data-entry/steps/calling/passports'))->toBe([$passport->id]);

        // Clearing the mark is an undo, for the operations head only.
        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => null])->assertForbidden();

        expect(idsOf($fitTab))->toBe([])
            ->and(idsOf('/api/data-entry/steps/calling/passports'))->toBe([$passport->id]);
    })->with([
        'fit tab' => ['fit', 'fit'],
        'expiring soon tab' => ['expiring_soon', 'expiring_soon'],
        // The Fit tab includes expiring-soon passports too.
        'expiring soon, in the fit tab' => ['expiring_soon', 'fit'],
    ]);

    it('leaves the Expired and Unfit tabs as they were', function () {
        // Marked, then the medical ran out: still under Expired.
        $expiredMarked = fitPassport('expired', 'online');
        $expired = fitPassport('expired');
        $unfit = PassportEntry::factory()->withMedicalSlip()->create();
        MedicalRecord::factory()->for($unfit)->unfit()->create();
        $pending = PassportEntry::factory()->withMedicalSlip()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(idsOf('/api/data-entry/passports?mode=medical&medical_status=expired'))->toEqualCanonicalizing([$expiredMarked->id, $expired->id])
            ->and(idsOf('/api/data-entry/passports?mode=medical&medical_status=unfit'))->toBe([$unfit->id])
            ->and(idsOf('/api/data-entry/passports?mode=medical&medical_status=pending'))->toBe([$pending->id]);
    });

    it('counts the Medical tabs exactly as the lists do', function () {
        fitPassport('fit');
        fitPassport('fit', 'online');
        fitPassport('expiring_soon');
        fitPassport('expiring_soon', 'online');
        fitPassport('expired');
        fitPassport('expired', 'online');
        $legacy = fitPassport('fit');
        PassportStepRecord::factory()->for($legacy)->step('calling')->on('2026-04-02')->create();
        $unfit = PassportEntry::factory()->withMedicalSlip()->create();
        MedicalRecord::factory()->for($unfit)->unfit()->create();
        PassportEntry::factory()->withMedicalSlip()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $step2 = $this->getJson('/api/data-entry/workflow/summary')->assertOk()->json('data.step2');

        foreach (['pending', 'fit', 'expiring_soon', 'expired', 'unfit'] as $tab) {
            expect($step2[$tab])->toBe($this->getJson("/api/data-entry/passports?mode=medical&medical_status={$tab}&per_page=1")->json('meta.total'), $tab);
        }

        expect($step2)->toMatchArray(['pending' => 1, 'fit' => 3, 'expiring_soon' => 1, 'expired' => 2, 'unfit' => 1]);
    });

    it('moves a passport from the Fit tab to Calling pending when marked, and back when cleared', function (string $state, string $tab) {
        $passport = fitPassport($state);
        Sanctum::actingAs(User::factory()->dataEntry()->create());
        $fitTab = "/api/data-entry/passports?mode=medical&medical_status={$tab}";

        expect(idsOf($fitTab))->toBe([$passport->id])
            ->and(idsOf('/api/data-entry/steps/calling/passports'))->toBe([]);

        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])
            ->assertOk()
            ->assertJsonPath('data.entry_mode', ['value' => 'online', 'label' => 'Online'])
            ->assertJsonPath('data.latest_status.text', 'Ready for Calling (Online)');

        expect(idsOf($fitTab))->toBe([])
            ->and(idsOf('/api/data-entry/steps/calling/passports'))->toBe([$passport->id]);

        // Undo (clearing the mark) is for admins.
        Sanctum::actingAs(User::factory()->operationHead()->create());
        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => null])
            ->assertOk()
            ->assertJsonPath('data.latest_status.text', 'Medical fit - mark Online');

        expect(idsOf($fitTab))->toBe([$passport->id])
            ->and(idsOf('/api/data-entry/steps/calling/passports'))->toBe([]);
    })->with([
        'fit tab' => ['fit', 'fit'],
        'expiring soon tab' => ['expiring_soon', 'expiring_soon'],
        // The Fit tab includes expiring-soon passports too.
        'expiring soon, in the fit tab' => ['expiring_soon', 'fit'],
    ]);
});

describe('calling list and summary', function () {
    it('leaves the mark out of every Calling tab', function () {
        $pending = fitPassport('fit', 'online');
        $inProcess = fitPassport('fit', 'online');
        PassportStepRecord::factory()->for($inProcess)->step('calling')->status(StepStatus::InProcess)->on('2026-04-25')->create();
        $legacyDone = fitPassport();
        PassportStepRecord::factory()->for($legacyDone)->step('calling')->on('2026-04-02')->create();
        Sanctum::actingAs(User::factory()->accounts()->create());

        // Pending holds both: no record yet, and an older calling-date record.
        $pendingRows = $this->getJson('/api/data-entry/steps/calling/passports?tab=pending')->json('data');
        $permittedRows = $this->getJson('/api/data-entry/steps/calling/passports?tab=permitted')->json('data');

        expect(collect($pendingRows)->pluck('id')->all())->toEqualCanonicalizing([$pending->id, $inProcess->id])
            ->and(collect($permittedRows)->pluck('id')->all())->toBe([$legacyDone->id]);

        foreach ([...$pendingRows, ...$permittedRows] as $row) {
            expect($row)->not->toHaveKey('entry_mode');
        }
    });

    it('counts Calling pending exactly as the list does', function () {
        fitPassport('fit');
        fitPassport('expiring_soon');
        fitPassport('fit', 'online');
        fitPassport('expiring_soon', 'online');
        // Marked, then the medical ran out: not in Calling pending.
        fitPassport('expired', 'online');
        $legacy = fitPassport();
        PassportStepRecord::factory()->for($legacy)->step('calling')->status(StepStatus::Rejected)->on('2026-04-25')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $tabs = $this->getJson('/api/data-entry/workflow/summary')->json('data.step_tabs.calling');

        foreach (['pending', 'permitted'] as $tab) {
            expect($tabs[$tab])->toBe($this->getJson("/api/data-entry/steps/calling/passports?tab={$tab}&per_page=1")->json('meta.total'), $tab);
        }

        // The old rejected calling record no longer counts: that passport is
        // simply fit and not marked.
        expect($tabs)->toBe(['pending' => 2, 'permitted' => 0])
            ->and($this->getJson('/api/data-entry/workflow/summary')->json('data.stages.medical.fit'))->toBe(3);
    });
});

describe('Key-in removed', function () {
    it('rejects "keyin" with a clear message', function () {
        $passport = fitPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'keyin'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entry_mode' => 'The only mark is Online: send entry_mode "online", or null to clear it.']);

        expect($passport->fresh()->entry_mode)->toBeNull();
    });

    it('never says Key-in in any response', function () {
        $marked = fitPassport('fit', 'online');
        $unmarked = fitPassport('expiring_soon');
        $started = fitPassport('fit', 'online');
        PassportStepRecord::factory()->for($started)->step('calling')->status(StepStatus::InProcess)->on('2026-04-25')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $responses = [
            $this->getJson('/api/data-entry/workflow/config'),
            $this->getJson('/api/data-entry/defaults'),
            $this->getJson('/api/data-entry/workflow/summary'),
            $this->getJson("/api/data-entry/passports/{$marked->id}"),
            $this->getJson('/api/data-entry/passports?medical_status=all'),
            $this->getJson('/api/data-entry/passports?view=overview&medical_status=all'),
            $this->getJson('/api/data-entry/passports?mode=medical&medical_status=fit'),
            $this->getJson('/api/data-entry/passports?mode=medical&medical_status=expiring_soon'),
            $this->getJson('/api/data-entry/steps/calling/passports?tab=pending'),
            $this->getJson('/api/data-entry/steps/calling/passports?tab=in_process'),
            $this->patchJson("/api/data-entry/passports/{$unmarked->id}/entry-mode", ['entry_mode' => 'online']),
            $this->patchJson("/api/data-entry/passports/{$unmarked->id}/entry-mode", ['entry_mode' => null]),
            $this->postJson("/api/data-entry/passports/{$unmarked->id}/steps/calling/update", ['receive_date' => '2026-04-25', 'issue_date' => '2026-04-25', 'valid_until' => '2026-10-25']),
        ];

        foreach ($responses as $response) {
            expect(strtolower($response->getContent()))->not->toContain('key-in')->not->toContain('keyin');
        }

        expect($responses[3]->json('data.latest_status.text'))->toBe('Ready for Calling (Online)')
            ->and($responses[11]->status())->toBe(403); // clearing the mark: the operations head only
    });
});
