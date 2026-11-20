<?php

use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\RecruitmentAgent;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * The passport history shows who did each action, entry to flight: the slip, the
 * Online mark and its undo, step edits and undos, and the agent's approval.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 10:00', 'Asia/Dhaka')->utc());
});

/** @return list<array<string, mixed>> */
function activityHistory(PassportEntry $passport): array
{
    return test()->getJson("/api/data-entry/passports/{$passport->id}/history")->assertOk()->json('data');
}

/** The history event with this title. */
function activityEvent(PassportEntry $passport, string $title): ?array
{
    return collect(activityHistory($passport))->firstWhere('title', $title);
}

it('shows who added the slip and who marked it Online (and undid it)', function () {
    $passport = PassportEntry::factory()->create(['passport_received_date' => '2026-03-01']);
    $clerk = User::factory()->dataEntry()->create(['name' => 'Rahim Clerk']);
    Sanctum::actingAs($clerk);

    $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", slipPayload(['medical_slip_date' => '2026-04-01'], $passport->id))->assertOk();
    recordMedical($passport->id, '2026-04-10', 'fit')->assertCreated();
    $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => 'online'])->assertOk();

    $head = User::factory()->operationHead()->create(['name' => 'Karim Head']);
    Sanctum::actingAs($head);
    $this->patchJson("/api/data-entry/passports/{$passport->id}/entry-mode", ['entry_mode' => null])->assertOk();

    $slip = activityEvent($passport, 'Medical slip added');
    expect($slip['recorded_by']['name'])->toBe('Rahim Clerk')
        ->and(collect($slip['details'])->pluck('label'))->toContain('Medical center');

    expect(activityEvent($passport, 'Marked Online')['recorded_by']['name'])->toBe('Rahim Clerk')
        ->and(activityEvent($passport, 'Marked Online')['step_key'])->toBe('medical')
        ->and(activityEvent($passport, 'Online mark undone')['recorded_by']['name'])->toBe('Karim Head');
});

it('shows who edited a step and who undid it', function () {
    $passport = PassportEntry::factory()->withMedicalSlip('2026-01-10')->create(['passport_expiry_date' => '2035-01-01', 'entry_mode' => 'online']);
    MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
    $calling = PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
        ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);
    app(WorkflowService::class)->recompute($passport);

    $head = User::factory()->operationHead()->create(['name' => 'Karim Head']);
    Sanctum::actingAs($head);
    $this->patchJson("/api/data-entry/step-records/{$calling->id}", [
        'status' => 'completed', 'step_date' => '2026-04-03',
        'details' => ['receive_date' => '2026-04-03', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01',
    ])->assertSuccessful();
    $this->postJson("/api/data-entry/passports/{$passport->id}/undo-step/calling")->assertOk();

    $events = collect(activityHistory($passport));
    $edited = $events->first(fn ($e) => str_ends_with($e['title'], ' edited') && $e['step_key'] === 'calling');
    $undone = $events->first(fn ($e) => str_starts_with($e['title'], 'Undo ') && $e['step_key'] === 'calling');
    expect($edited['recorded_by']['name'])->toBe('Karim Head')
        ->and($undone['recorded_by']['name'])->toBe('Karim Head');
});

it('shows who assigned and approved the agent, on the Passport step', function () {
    $passport = PassportEntry::factory()->withoutAgent()->create(['passport_received_date' => '2026-03-01']);
    $agent = new RecruitmentAgent(['name' => 'Tania Treaders', 'rl_number' => 'RL-2201']);
    $agent->is_active = true;
    $agent->save();

    Sanctum::actingAs(User::factory()->dataEntry()->create(['name' => 'Rahim Clerk']));
    $assignment = $this->postJson("/api/data-entry/passports/{$passport->id}/agent-assignments", [
        'recruitment_agent_id' => $agent->id, 'assigned_date' => '2026-04-01',
    ])->assertCreated()->json('data.id');

    Sanctum::actingAs(User::factory()->operationHead()->create(['name' => 'Karim Head']));
    $this->postJson("/api/operation-head/agent-approvals/{$assignment}/approve")->assertOk();

    $assigned = activityEvent($passport, 'Agent assigned (waiting for approval)');
    $approved = activityEvent($passport, 'Agent approved');
    expect($assigned['recorded_by']['name'])->toBe('Rahim Clerk')
        ->and($assigned['step_key'])->toBe('passport')
        ->and($approved['recorded_by']['name'])->toBe('Karim Head')
        ->and(collect($approved['details'])->firstWhere('label', 'Agent')['value'])->toBe('Tania Treaders');
});
