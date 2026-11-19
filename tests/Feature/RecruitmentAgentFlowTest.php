<?php

use App\Enums\StepStatus;
use App\Models\Company;
use App\Models\MedicalRecord;
use App\Models\Party;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Models\RecruitmentAgent;
use App\Models\User;
use App\Services\WorkflowService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * The Recruitment Agent process: one "Own" agent (our company) besides the partner
 * agents, no step without an approved agent, partner passports "Received at Office"
 * after BMET before the flight, and the agent-wise tabs of the step pages.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 10:00', 'Asia/Dhaka')->utc());
});

function raPartner(string $name = 'Karim Overseas', string $rl = 'RL-2001'): RecruitmentAgent
{
    $agent = new RecruitmentAgent(['name' => $name, 'rl_number' => $rl]);
    $agent->is_active = true;
    $agent->save();

    return $agent;
}

/**
 * Move the passport's current (approved) agent to $agent.
 */
function raWithAgent(PassportEntry $passport, RecruitmentAgent $agent): PassportEntry
{
    PassportAgentAssignment::query()->where('passport_entry_id', $passport->id)->current()
        ->update(['recruitment_agent_id' => $agent->id]);

    return $passport->fresh();
}

/**
 * A passport with calling and visa done; BMET cleared on 2026-04-04 when $cleared.
 */
function raBmetPassport(bool $cleared = true, bool $agent = true): PassportEntry
{
    $factory = PassportEntry::factory()->withMedicalSlip('2026-01-10');
    $passport = ($agent ? $factory : $factory->withoutAgent())->create(['passport_expiry_date' => '2035-01-01', 'entry_mode' => 'online']);
    MedicalRecord::factory()->for($passport)->on('2026-04-01')->create();
    PassportStepRecord::factory()->for($passport)->step('calling')->on('2026-04-02')
        ->create(['details' => ['receive_date' => '2026-04-02', 'issue_date' => '2026-04-01'], 'valid_until' => '2026-10-01']);
    PassportStepRecord::factory()->for($passport)->step('visa')->status(StepStatus::Completed)->on('2026-04-03')
        ->create(['reference_no' => null, 'details' => ['issue_date' => '2026-04-03'], 'valid_until' => '2026-10-03']);
    if ($cleared) {
        PassportStepRecord::factory()->for($passport)->step('bmet')->status(StepStatus::Completed)->on('2026-04-04')
            ->create(['reference_no' => null, 'details' => ['training_certificate_date' => '2026-03-20', 'finger_date' => '2026-03-25']]);
    }
    app(WorkflowService::class)->recompute($passport);

    return $passport->fresh();
}

/**
 * @return list<int>
 */
function raStepTab(string $step, string $tab, string $query = ''): array
{
    return test()->getJson("/api/data-entry/steps/{$step}/passports?tab={$tab}&per_page=100{$query}")->assertOk()->json('data.*.id');
}

describe('the Own agent', function () {
    it('is added once by the operations head, comes first, and can be deactivated like any agent', function () {
        Sanctum::actingAs(User::factory()->operationHead()->create());
        raPartner('Alpha Agency');

        $own = $this->postJson('/api/admin/recruitment-agents', ['name' => 'Afwan Associates', 'rl_number' => 'rl-1234', 'is_own' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_own', true)
            ->assertJsonPath('data.rl_number', 'RL-1234')
            ->json('data');

        $this->postJson('/api/admin/recruitment-agents', ['name' => 'Second Own', 'rl_number' => 'RL-9', 'is_own' => true])
            ->assertJsonValidationErrors(['is_own' => 'The Own agent (our company) already exists.']);
        $this->postJson('/api/admin/recruitment-agents', ['name' => 'No RL', 'is_own' => true])
            ->assertJsonValidationErrors(['rl_number']);

        // Like any agent, the Own agent can be deactivated and activated again.
        $this->patchJson("/api/admin/recruitment-agents/{$own['id']}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->patchJson("/api/admin/recruitment-agents/{$own['id']}", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        $this->patchJson("/api/admin/recruitment-agents/{$own['id']}", ['is_own' => false])
            ->assertJsonValidationErrors(['is_own']);
        $this->patchJson("/api/admin/recruitment-agents/{$own['id']}", ['phone' => '01700000000'])->assertOk();

        expect($this->getJson('/api/admin/recruitment-agents')->json('data.*.name'))->toBe(['Afwan Associates', 'Alpha Agency']);
    });

    it('is left out of agent payments, its lookup and its ledger', function () {
        $own = RecruitmentAgent::query()->create(['name' => 'Own Co', 'rl_number' => 'RL-OWN']);
        $own->forceFill(['is_own' => true])->save();
        $partner = raPartner();
        Sanctum::actingAs(User::factory()->accounts()->create());

        expect($this->getJson('/api/recruitment-agents/lookup')->json('data.*.id'))->toBe([$own->id, $partner->id])
            ->and($this->getJson('/api/recruitment-agents/lookup?partners=1')->json('data.*.id'))->toBe([$partner->id])
            ->and($this->getJson('/api/accounts/recruitment-agents')->json('data.*.id'))->toBe([$partner->id]);
        $this->getJson("/api/accounts/recruitment-agents/{$own->id}")->assertNotFound();

        $this->postJson('/api/accounts/cash-payments', [
            'date' => '2026-04-10', 'payee_type' => 'agent', 'recruitment_agent_id' => $own->id,
            'payment_type' => 'agent_payment', 'amount' => 100, 'payment_method' => 'cash',
        ])->assertJsonValidationErrors(['recruitment_agent_id']);
    });
});

describe('no step without an approved agent', function () {
    it('blocks the next steps until the agent is approved, but not corrections', function () {
        $passport = raBmetPassport(cleared: false, agent: false);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/bmet/clearance", ['date' => '2026-04-20'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'agent_missing');

        PassportAgentAssignment::query()->create([
            'passport_entry_id' => $passport->id, 'recruitment_agent_id' => raPartner()->id,
            'assigned_date' => '2026-04-10', 'status' => 'pending',
        ]);
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/bmet/clearance", ['date' => '2026-04-20'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'agent_not_approved');

        PassportAgentAssignment::query()->where('passport_entry_id', $passport->id)->update(['status' => 'approved', 'approved_at' => now()]);
        $this->postJson("/api/data-entry/passports/{$passport->id}/steps/bmet/training", ['date' => '2026-04-20'])->assertSuccessful();
    });

    it('blocks the medical result without an approved agent', function () {
        $passport = PassportEntry::factory()->withoutAgent()->withMedicalSlip('2026-04-01')->create(['passport_received_date' => '2026-03-01']);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        recordMedical($passport->id, '2026-04-10', 'fit')->assertUnprocessable()->assertJsonPath('code', 'agent_missing');
    });
});

describe('deactivating a partner agent', function () {
    it('is refused while the agent still has passports in process (flight not done)', function () {
        $karim = raPartner();
        $passport = raWithAgent(raBmetPassport(), $karim);
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->patchJson("/api/admin/recruitment-agents/{$karim->id}", ['is_active' => false])
            ->assertJsonValidationErrors(['is_active' => 'This agent still has 1 passport(s) in process (flight not done). Finish them or move them to another agent before deactivating.']);

        // Once the flight is done, the agent can be deactivated.
        PassportStepRecord::factory()->for($passport)->step('flight')->status(StepStatus::Completed)->flight('2026-04-20', '2026-04-20')->create();
        app(WorkflowService::class)->recompute($passport);
        $this->patchJson("/api/admin/recruitment-agents/{$karim->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    });

    it('also counts a passport waiting for approval, but not a returned one', function () {
        $karim = raPartner();
        $pending = PassportEntry::factory()->withoutAgent()->create();
        PassportAgentAssignment::query()->create([
            'passport_entry_id' => $pending->id, 'recruitment_agent_id' => $karim->id,
            'assigned_date' => '2026-04-10', 'status' => 'pending',
        ]);
        Sanctum::actingAs(User::factory()->operationHead()->create());

        $this->patchJson("/api/admin/recruitment-agents/{$karim->id}", ['is_active' => false])
            ->assertJsonValidationErrors(['is_active']);

        PassportAgentAssignment::query()->where('passport_entry_id', $pending->id)->update(['status' => 'returned']);
        $this->patchJson("/api/admin/recruitment-agents/{$karim->id}", ['is_active' => false])->assertOk();
    });
});

describe('after BMET clearance', function () {
    it('puts every passport, partner or Own, straight on the Flight list (no Received at Office)', function () {
        $partner = raWithAgent(raBmetPassport(), raPartner());
        $own = raBmetPassport();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        expect(raStepTab('flight', 'pending'))->toEqualCanonicalizing([$partner->id, $own->id]);
        $this->postJson("/api/data-entry/passports/{$partner->id}/steps/flight/booking", ['booking_date' => '2026-05-20'])->assertSuccessful();
        $this->postJson("/api/data-entry/passports/{$partner->id}/office-receive", ['office_received_date' => '2026-04-25'])->assertNotFound();
    });
});

describe('agent-wise tabs', function () {
    it('filters each step tab by agent and counts its passports per agent', function () {
        $karim = raPartner();
        $a = raWithAgent(raBmetPassport(cleared: false), $karim);
        $b = raBmetPassport(cleared: false);
        $c = raBmetPassport(cleared: false, agent: false);
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->getJson('/api/data-entry/steps/bmet/passports?tab=pending&per_page=100')->assertOk();
        $own = RecruitmentAgent::query()->where('is_own', true)->firstOrFail();

        expect($response->json('agent_counts.none'))->toBe(1)
            ->and($response->json('agent_counts.approved'))->toBe(2)
            ->and(collect($response->json('agent_counts.agents'))->map(fn ($r) => [$r['id'], $r['is_own'], $r['count']])->all())
            ->toBe([[$own->id, true, 1], [$karim->id, false, 1]])
            ->and($response->json('data.*.recruitment_agent.id'))->toContain($karim->id)
            ->and(raStepTab('bmet', 'pending', "&recruitment_agent_id={$karim->id}"))->toBe([$a->id])
            ->and(raStepTab('bmet', 'pending', "&recruitment_agent_id={$own->id}"))->toBe([$b->id])
            ->and(raStepTab('bmet', 'pending', '&recruitment_agent_id=none'))->toBe([$c->id]);
    });

    it('adds the agent counts to the Medical page list', function () {
        $karim = raPartner();
        raWithAgent(PassportEntry::factory()->withMedicalSlip('2026-04-01')->create(), $karim);
        PassportEntry::factory()->withMedicalSlip('2026-04-01')->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $response = $this->getJson("/api/data-entry/passports?mode=medical&medical_status=pending&with_agent_counts=1&recruitment_agent_id={$karim->id}")->assertOk();

        expect($response->json('meta.total'))->toBe(1)
            ->and($response->json('agent_counts.approved'))->toBe(2)
            ->and(collect($response->json('agent_counts.agents'))->pluck('count', 'id')->all())->toBe([
                RecruitmentAgent::query()->where('is_own', true)->value('id') => 1,
                $karim->id => 1,
            ]);
    });
});

describe('choosing the agent when adding a passport', function () {
    it('sends the chosen agent to the operations head with the new passport', function () {
        $karim = raPartner();
        $reference = Party::factory()->create();
        $company = Company::factory()->create();
        Sanctum::actingAs(User::factory()->dataEntry()->create());

        $id = $this->post('/api/data-entry/passports', [
            'passport_name' => 'Abdul Karim', 'passport_number' => 'A1234567', 'country_code' => 'BD',
            'date_of_birth' => '1995-01-01', 'reference_id' => $reference->id, 'company_id' => $company->id,
            'passport_received_date' => '2026-04-20', 'passport_expiry_date' => '2034-01-01',
            'recruitment_agent_id' => $karim->id, 'scan' => fakeScan(),
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.agent_status', 'pending')
            ->json('data.id');

        expect(PassportAgentAssignment::query()->where('passport_entry_id', $id)->sole())
            ->recruitment_agent_id->toBe($karim->id)
            ->status->value->toBe('pending')
            ->assigned_date->toDateString()->toBe('2026-05-01');
    });
});
