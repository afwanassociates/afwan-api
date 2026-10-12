<?php

use App\Enums\StepStatus;
use App\Models\Company;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\User;
use App\Support\Workflow;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Today in Dhaka is 2026-05-01 (12:00).
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 12:00', 'Asia/Dhaka')->utc());
});

/**
 * A passport with a fit medical (valid until $validUntil), marked $mode, with
 * step records [step => status] in order.
 *
 * @param  array<string, StepStatus>  $records
 */
function mixedPassport(array $records = [], ?string $mode = 'online', string $validUntil = '2026-07-01', ?Company $company = null): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create([
        'passport_expiry_date' => '2035-01-01',
        'entry_mode' => $mode,
        ...($company ? ['company_id' => $company->id] : []),
    ]);
    MedicalRecord::factory()->for($passport)->validUntil($validUntil)->create();

    $day = 2;
    foreach ($records as $step => $status) {
        $factory = PassportStepRecord::factory()->for($passport)->step($step)->status($status)->on('2026-04-'.str_pad((string) $day++, 2, '0', STR_PAD_LEFT));
        $factory = match (true) {
            $step === 'flight' => $factory->flight('2026-04-30'),
            $step === 'calling' && $status === StepStatus::Completed => $factory->state(['details' => ['receive_date' => '2026-04-0'.($day - 1), 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']),
            default => $factory,
        };
        $factory->create();
    }

    return $passport->fresh();
}

/**
 * Every state, including the ones that used to make the counts disagree.
 */
function seedMixedStates(): Company
{
    test()->seed();

    $done = StepStatus::Completed;
    $acme = Company::factory()->create(['name' => 'Acme Manpower Searchable']);

    // Calling: ready, ready but the medical ran out (not Pending), an older
    // calling-date record (Pending), the same with an expired medical (not
    // Pending), one started before marks existed, an old rejected one.
    mixedPassport([], 'online', '2026-07-01', $acme);
    mixedPassport([], 'online', '2026-04-20');
    mixedPassport(['calling' => StepStatus::InProcess]);
    mixedPassport(['calling' => StepStatus::InProcess], 'online', '2026-04-20');
    mixedPassport(['calling' => StepStatus::InProcess], null, '2026-07-01', $acme);
    mixedPassport(['calling' => StepStatus::Rejected]);
    // Not marked yet: Medical only.
    mixedPassport([], null);
    // Permitted (no visa yet), and permitted with a visa in each state.
    mixedPassport(['calling' => $done], 'online', '2026-07-01', $acme);
    mixedPassport(['calling' => $done], null);
    mixedPassport(['calling' => $done, 'visa' => StepStatus::InProcess]);
    mixedPassport(['calling' => $done, 'visa' => StepStatus::Rejected], 'online', '2026-07-01', $acme);
    mixedPassport(['calling' => $done, 'visa' => $done]);
    // BMET and Flight in every state.
    mixedPassport(['calling' => $done, 'visa' => $done, 'bmet' => StepStatus::InProcess]);
    mixedPassport(['calling' => $done, 'visa' => $done, 'bmet' => StepStatus::Rejected]);
    mixedPassport(['calling' => $done, 'visa' => $done, 'bmet' => $done]);
    mixedPassport(['calling' => $done, 'visa' => $done, 'bmet' => $done, 'flight' => StepStatus::InProcess]);
    mixedPassport(['calling' => $done, 'visa' => $done, 'bmet' => $done, 'flight' => StepStatus::Rejected]);
    mixedPassport(['calling' => $done, 'visa' => $done, 'bmet' => $done, 'flight' => $done], 'online', '2026-07-01', $acme);
    // Deleted passports, at every step: never counted.
    foreach ([[], ['calling' => StepStatus::InProcess], ['calling' => $done], ['calling' => $done, 'visa' => StepStatus::InProcess]] as $records) {
        mixedPassport($records)->delete();
    }
    // Unfit: never in a step.
    tap(PassportEntry::factory()->withMedicalSlip()->create(['entry_mode' => 'online']), fn ($p) => MedicalRecord::factory()->for($p)->unfit()->create());

    return $acme;
}

it('gives the same counts in the list, its tab counts and the summary, for every step and tab', function () {
    $acme = seedMixedStates();
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    $summary = $this->getJson('/api/data-entry/workflow/summary')->assertOk()->json('data');
    $workflow = app(Workflow::class);
    $checked = 0;

    foreach ($workflow->recordStepKeys() as $step) {
        $tabCounts = $this->getJson("/api/data-entry/steps/{$step}/passports")->assertOk()->json('tab_counts');
        $searchedCounts = $this->getJson("/api/data-entry/steps/{$step}/passports?company=searchable")->json('tab_counts');

        expect(array_keys($tabCounts))->toBe(array_column($workflow->tabs($step), 'key'))
            ->and($summary['step_tabs'][$step])->toBe($tabCounts, "{$step}: summary vs tab counts");

        foreach ($tabCounts as $tab => $count) {
            $listed = $this->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}&per_page=100")->json('meta.total');
            $searched = $this->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}&company=searchable&per_page=100")->json('meta.total');

            expect($listed)->toBe($count, "{$step} {$tab}: list vs tab count")
                ->and($searched)->toBe($searchedCounts[$tab], "{$step} {$tab}: searched list vs searched tab count");
            $checked++;
        }

        // The step bar (stage total) and the sidebar badge (waiting) are the Pending tab.
        expect($summary['stages'][$step])->toBe(['total' => $tabCounts['pending'], 'waiting' => $tabCounts['pending']], "{$step}: stage counts");
    }

    // Calling 2 tabs, Visa 3, BMET 4, Flight 4.
    expect($checked)->toBe(13)
        ->and($summary['step3']['ready'])->toBe($summary['step_tabs']['calling']['pending'])
        // The calling cases above, on top of the seeded data: the ready one, the
        // older calling-date record, the unmarked older record and the old
        // rejected one are Pending; the expired medicals are not.
        ->and($summary['step_tabs']['calling']['pending'])->toBeGreaterThanOrEqual(4);
});

it('pins the calling and visa tabs on known data', function () {
    $done = StepStatus::Completed;
    $ready = mixedPassport();
    mixedPassport([], 'online', '2026-04-20');
    $legacy = mixedPassport(['calling' => StepStatus::InProcess], null);
    mixedPassport(['calling' => StepStatus::InProcess], 'online', '2026-04-20');
    $permitted = mixedPassport(['calling' => $done]);
    $submitted = mixedPassport(['calling' => $done, 'visa' => StepStatus::InProcess]);
    mixedPassport(['calling' => $done])->delete();
    Sanctum::actingAs(User::factory()->dataEntry()->create());

    $tabs = $this->getJson('/api/data-entry/workflow/summary')->json('data.step_tabs');
    $ids = fn (string $step, string $tab) => $this->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}&per_page=100")->json('data.*.id');

    expect($tabs['calling'])->toBe(['pending' => 2, 'permitted' => 1])
        ->and($ids('calling', 'pending'))->toEqualCanonicalizing([$ready->id, $legacy->id])
        ->and($ids('calling', 'permitted'))->toBe([$permitted->id])
        ->and($tabs['visa'])->toBe(['pending' => 1, 'issued' => 0, 'rejected' => 0])
        ->and($ids('visa', 'pending'))->toBe([$submitted->id]);
});
