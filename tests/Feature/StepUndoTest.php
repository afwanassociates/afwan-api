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
 * Undo Calling and Visa on the passport page (the operations head).
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 10:00', 'Asia/Dhaka')->utc());
});

/**
 * Medical fit and Online, calling permitted on 2026-04-02; then the visa as given.
 */
function undoPassport(?StepStatus $visa = null): PassportEntry
{
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create(['passport_expiry_date' => '2035-01-01', 'entry_mode' => 'online']);
    MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
    PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
        ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);
    if ($visa === StepStatus::InProcess) {
        PassportStepRecord::factory()->for($passport)->step('visa')->status(StepStatus::InProcess)->on('2026-04-03')->create(['reference_no' => null]);
    } elseif ($visa !== null) {
        PassportStepRecord::factory()->for($passport)->step('visa')->status($visa)->on('2026-04-10')->create([
            'reference_no' => 'VDR1',
            'details' => ['start_date' => '2026-04-03', 'issue_date' => '2026-04-10'],
            'valid_until' => $visa === StepStatus::Completed ? '2026-10-10' : null,
        ]);
    }
    app(WorkflowService::class)->recompute($passport);

    return $passport->fresh();
}

function undoUrl(PassportEntry $passport, string $step): string
{
    return "/api/data-entry/passports/{$passport->id}/undo-step/{$step}";
}

it('undoes an issued visa back to Visa submitted', function () {
    $passport = undoPassport(StepStatus::Completed);
    Sanctum::actingAs(User::factory()->operationHead()->create());

    $this->postJson(undoUrl($passport, 'visa'))->assertOk()
        ->assertJsonPath('data.current_stage', 'visa')
        ->assertJsonPath('data.stage_status', 'in_process');

    $visa = PassportStepRecord::query()->where('passport_entry_id', $passport->id)->where('step_key', 'visa')->sole();
    expect($visa->status)->toBe(StepStatus::InProcess)
        ->and($visa->step_date->toDateString())->toBe('2026-04-03')
        ->and($visa->valid_until)->toBeNull()
        ->and($visa->reference_no)->toBeNull();
});

it('undoes a submitted visa and then the calling, keeping both in the history as cancelled', function () {
    $passport = undoPassport(StepStatus::InProcess);
    Sanctum::actingAs(User::factory()->operationHead()->create());

    $this->postJson(undoUrl($passport, 'calling'))->assertUnprocessable()->assertJsonPath('code', 'later_step_exists');

    $this->postJson(undoUrl($passport, 'visa'))->assertOk()
        ->assertJsonPath('data.current_stage', 'visa')
        ->assertJsonPath('data.stage_status', 'waiting');
    $this->postJson(undoUrl($passport, 'calling'))->assertOk()
        ->assertJsonPath('data.current_stage', 'calling');

    expect(PassportStepRecord::query()->where('passport_entry_id', $passport->id)->count())->toBe(0)
        ->and(PassportStepRecord::onlyTrashed()->where('passport_entry_id', $passport->id)->count())->toBe(2);

    $titles = collect($this->getJson("/api/data-entry/passports/{$passport->id}/history")->json('data'))->pluck('title')->implode(' | ');
    expect($titles)->toContain('(cancelled)');
});

it('is refused once a later step (BMET) has records', function () {
    $passport = undoPassport(StepStatus::Completed);
    PassportStepRecord::factory()->for($passport)->step('bmet')->status(StepStatus::InProcess)->on('2026-04-12')
        ->create(['reference_no' => null, 'details' => ['training_certificate_date' => '2026-04-12']]);
    Sanctum::actingAs(User::factory()->operationHead()->create());

    $this->postJson(undoUrl($passport, 'visa'))->assertUnprocessable()->assertJsonPath('code', 'later_step_exists');
});

it('is for the operations head only, and only for Calling and Visa', function () {
    $passport = undoPassport(StepStatus::Completed);

    Sanctum::actingAs(User::factory()->dataEntry()->create());
    $this->postJson(undoUrl($passport, 'visa'))->assertForbidden();

    Sanctum::actingAs(User::factory()->operationHead()->create());
    $this->postJson(undoUrl($passport, 'flight'))->assertNotFound();
});
