<?php

use App\Enums\AgentAssignmentStatus;
use App\Enums\AgentCountDecision;
use App\Models\Party;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Models\RecruitmentAgent;
use App\Models\User;
use App\Services\AgentAssignmentService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * The operations head undoes a passport's agent approval on the passport page:
 * the assignment goes back to "waiting for approval".
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 10:00', 'Asia/Dhaka')->utc());
});

function undoAgentUrl(PassportEntry $passport): string
{
    return "/api/operation-head/passports/{$passport->id}/agent-approval/undo";
}

function undoAgentOther(): RecruitmentAgent
{
    $agent = new RecruitmentAgent(['name' => 'Other Overseas', 'rl_number' => 'RL-9901']);
    $agent->is_active = true;
    $agent->save();

    return $agent;
}

/** A passport (no medical slip) with one approved agent. */
function undoAgentPassport(): PassportEntry
{
    return PassportEntry::factory()->create(['passport_received_date' => '2026-03-01']);
}

it('puts the approved agent back to waiting for approval, keeping who undid it', function () {
    $passport = undoAgentPassport();
    $head = User::factory()->operationHead()->create();
    Sanctum::actingAs($head);

    $this->getJson("/api/data-entry/passports/{$passport->id}")
        ->assertJsonPath('data.agent_status', 'approved')
        ->assertJsonPath('data.can.undo_agent_approval', true);

    $this->postJson(undoAgentUrl($passport))->assertOk()->assertJsonPath('data.status', 'pending');

    $row = PassportAgentAssignment::query()->where('passport_entry_id', $passport->id)->sole();
    expect($row->status)->toBe(AgentAssignmentStatus::Pending)
        ->and($row->approved_by)->toBeNull()
        ->and($row->approval_undone_by)->toBe($head->id)
        ->and($row->approval_undone_at)->not->toBeNull();

    $this->getJson("/api/data-entry/passports/{$passport->id}")
        ->assertJsonPath('data.agent_status', 'pending')
        ->assertJsonPath('data.recruitment_agent', null)
        ->assertJsonPath('data.can.undo_agent_approval', false);

    $this->getJson("/api/data-entry/passports/{$passport->id}/agent-assignments")
        ->assertJsonPath('data.0.approval_undone_by.id', $head->id);
});

it('is for the operations head only', function () {
    $passport = undoAgentPassport();

    foreach (['dataEntry', 'admin'] as $role) {
        Sanctum::actingAs(User::factory()->{$role}()->create());
        $this->postJson(undoAgentUrl($passport))->assertForbidden();
    }
});

it('is refused once the medical slip is issued', function () {
    $passport = PassportEntry::factory()->withMedicalSlip('2026-04-01')->create(['passport_received_date' => '2026-03-01']);
    Sanctum::actingAs(User::factory()->operationHead()->create());

    $this->getJson("/api/data-entry/passports/{$passport->id}")->assertJsonPath('data.can.undo_agent_approval', false);
    $this->postJson(undoAgentUrl($passport))->assertUnprocessable()->assertJsonValidationErrors('assignment');
});

it('undoes an agent change: the passport is back with the previous agent', function () {
    $passport = PassportEntry::factory()->withMedicalSlip('2026-04-01')->create(['passport_received_date' => '2026-03-01']);
    $old = PassportAgentAssignment::query()->where('passport_entry_id', $passport->id)->sole();
    $other = undoAgentOther();
    $service = app(AgentAssignmentService::class);
    $change = $service->assign($passport, ['recruitment_agent_id' => $other->id, 'assigned_date' => '2026-04-20', 'remark' => 'Moved'], User::factory()->dataEntry()->create());
    $service->approve($change, User::factory()->operationHead()->create());
    expect($old->fresh()->count_decision)->toBe(AgentCountDecision::Waiting);

    Sanctum::actingAs(User::factory()->operationHead()->create());
    $this->postJson(undoAgentUrl($passport))->assertOk();

    $old->refresh();
    expect($old->isCurrent())->toBeTrue()
        ->and($old->ended_date)->toBeNull()
        ->and($old->count_decision)->toBeNull()
        ->and($change->fresh()->status)->toBe(AgentAssignmentStatus::Pending);
    $this->getJson("/api/data-entry/passports/{$passport->id}")
        ->assertJsonPath('data.recruitment_agent.id', $old->recruitment_agent_id)
        ->assertJsonPath('data.agent_status', 'pending');
});

it('is refused for an agent change once the accounts head decided about the old agent', function () {
    $passport = PassportEntry::factory()->withMedicalSlip('2026-04-01')->create(['passport_received_date' => '2026-03-01']);
    $old = PassportAgentAssignment::query()->where('passport_entry_id', $passport->id)->sole();
    $service = app(AgentAssignmentService::class);
    $change = $service->assign($passport, ['recruitment_agent_id' => undoAgentOther()->id, 'assigned_date' => '2026-04-20', 'remark' => 'Moved'], User::factory()->dataEntry()->create());
    $service->approve($change, User::factory()->operationHead()->create());
    $service->decide($old->fresh(), true, null, User::factory()->accountsHead()->create());

    Sanctum::actingAs(User::factory()->operationHead()->create());
    $this->postJson(undoAgentUrl($passport))->assertUnprocessable();
});

it('exports each Agent Approvals tab as CSV, with the same search', function () {
    $pending = PassportEntry::factory()->withoutAgent()->create(['passport_name' => 'Abdul Karim', 'passport_received_date' => '2026-03-01']);
    $agent = undoAgentOther();
    $clerk = User::factory()->dataEntry()->create(['name' => 'Rahim Clerk']);
    $service = app(AgentAssignmentService::class);
    $service->assign($pending, ['recruitment_agent_id' => $agent->id, 'assigned_date' => '2026-04-01'], $clerk);
    $returned = PassportEntry::factory()->withoutAgent()->create(['passport_name' => 'Jamal Uddin', 'passport_received_date' => '2026-03-01']);
    $back = $service->assign($returned, ['recruitment_agent_id' => $agent->id, 'assigned_date' => '2026-04-02'], $clerk);
    $head = User::factory()->operationHead()->create(['name' => 'Karim Head']);
    $service->sendBack($back, $head, 'Wrong agent');
    Sanctum::actingAs($head);

    $csv = $this->get('/api/operation-head/agent-approvals?status=pending&export=csv')
        ->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
    expect($csv)->toContain('"Passport name","Passport number"')
        ->toContain('ABDUL KARIM')->toContain('Other Overseas')->toContain('Rahim Clerk')
        ->not->toContain('JAMAL UDDIN');

    $csv = $this->get('/api/operation-head/agent-approvals?status=returned&export=csv')->assertOk()->streamedContent();
    expect($csv)->toContain('Return reason')->toContain('JAMAL UDDIN')->toContain('Wrong agent')->toContain('Karim Head')
        ->not->toContain('ABDUL KARIM');

    Sanctum::actingAs($clerk);
    $this->get('/api/operation-head/agent-approvals?status=pending&export=csv')->assertForbidden();
});

it('pages each Agent Approvals tab (up to 300 rows per page)', function () {
    $agent = undoAgentOther();
    $clerk = User::factory()->dataEntry()->create();
    foreach (range(1, 3) as $i) {
        $passport = PassportEntry::factory()->withoutAgent()->create(['passport_received_date' => '2026-03-01']);
        app(AgentAssignmentService::class)->assign($passport, ['recruitment_agent_id' => $agent->id, 'assigned_date' => '2026-04-01'], $clerk);
    }
    Sanctum::actingAs(User::factory()->operationHead()->create());

    $this->getJson('/api/operation-head/agent-approvals?status=pending&per_page=2&page=2')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/operation-head/agent-approvals?per_page=300')->assertOk();
    $this->getJson('/api/operation-head/agent-approvals?per_page=301')->assertUnprocessable();
});

it('approves reference-wise: the references waiting, then all of one reference', function () {
    $agent = undoAgentOther();
    $clerk = User::factory()->dataEntry()->create();
    $shamim = Party::factory()->create(['name' => 'Shamim Ahmed']);
    $halim = Party::factory()->create(['name' => 'Abdul Halim']);
    $passports = collect([$shamim, $shamim, $shamim, $halim])->map(fn ($ref) => PassportEntry::factory()->withoutAgent()
        ->create(['reference_id' => $ref->id, 'passport_received_date' => '2026-03-01']));

    // Data entry sends all of a reference at once (bulk assign).
    Sanctum::actingAs($clerk);
    $this->postJson('/api/data-entry/agent-assignments/bulk', [
        'passport_ids' => $passports->pluck('id')->all(),
        'recruitment_agent_id' => $agent->id,
        'assigned_date' => '2026-04-01',
    ])->assertOk()->assertJsonCount(4, 'data.updated');

    Sanctum::actingAs(User::factory()->operationHead()->create());
    $this->getJson('/api/operation-head/agent-approvals/references')->assertOk()
        ->assertJsonPath('data.0.name', $shamim->name)
        ->assertJsonPath('data.0.pending', 3)
        ->assertJsonPath('data.1.pending', 1);
    $this->getJson("/api/operation-head/agent-approvals?reference_id={$shamim->id}")->assertJsonCount(3, 'data');

    $this->postJson('/api/operation-head/agent-approvals/approve-reference', ['reference_id' => $shamim->id])->assertOk()
        ->assertJsonCount(3, 'data.updated')
        ->assertJsonCount(0, 'data.skipped');

    $this->getJson('/api/operation-head/agent-approvals/references')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $halim->id);
    expect(PassportAgentAssignment::query()->where('status', 'approved')->count())->toBe(3);
});

it('approve-reference is for the passport managers, and skips the ones they assigned themselves', function () {
    $agent = undoAgentOther();
    $ref = Party::factory()->create();
    $passport = PassportEntry::factory()->withoutAgent()->create(['reference_id' => $ref->id, 'passport_received_date' => '2026-03-01']);
    $clerk = User::factory()->dataEntry()->create();
    app(AgentAssignmentService::class)->assign($passport, ['recruitment_agent_id' => $agent->id, 'assigned_date' => '2026-04-01'], $clerk);

    Sanctum::actingAs($clerk);
    $this->postJson('/api/operation-head/agent-approvals/approve-reference', ['reference_id' => $ref->id])->assertForbidden();
});
