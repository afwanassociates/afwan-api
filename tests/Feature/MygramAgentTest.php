<?php

use App\Models\CashPayment;
use App\Models\MygramAgent;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Today in Dhaka is 2026-05-01.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-01 10:00', 'Asia/Dhaka')->utc());
    $this->clerk = User::factory()->dataEntry()->create();
    $this->head = User::factory()->operationHead()->create();
    $this->accountsHead = User::factory()->accountsHead()->create();
});

function agent(string $name = 'Karim Overseas', string $rl = 'RL-1001'): MygramAgent
{
    $agent = new MygramAgent(['name' => $name, 'rl_number' => $rl]);
    $agent->is_active = true;
    $agent->save();

    return $agent;
}

function agentPassport(): PassportEntry
{
    return PassportEntry::factory()->create(['passport_received_date' => '2026-03-01']);
}

function assignAgent(PassportEntry $passport, MygramAgent $agent, array $overrides = [])
{
    return test()->postJson("/api/data-entry/passports/{$passport->id}/agent-assignments", [
        'mygram_agent_id' => $agent->id,
        'assigned_date' => '2026-04-01',
        ...$overrides,
    ]);
}

describe('agent list', function () {
    it('lets only the operations head add, edit and deactivate agents', function () {
        Sanctum::actingAs($this->head);

        $id = $this->postJson('/api/admin/mygram-agents', ['name' => ' Karim  Overseas ', 'rl_number' => 'rl-1001', 'phone' => '01711000000'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'AG-001')
            ->assertJsonPath('data.name', 'Karim Overseas')
            ->assertJsonPath('data.rl_number', 'RL-1001')
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->postJson('/api/admin/mygram-agents', ['name' => 'Other', 'rl_number' => 'RL-1001'])
            ->assertJsonValidationErrors(['rl_number' => 'Another agent already has this RL number.']);
        $this->postJson('/api/admin/mygram-agents', ['name' => 'Other'])
            ->assertJsonValidationErrors(['rl_number' => 'Enter the RL number.']);
        $this->postJson('/api/admin/mygram-agents', ['name' => 'Second', 'rl_number' => 'RL-2'])
            ->assertJsonPath('data.code', 'AG-002');

        $this->patchJson("/api/admin/mygram-agents/{$id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/admin/mygram-agents?is_active=0')->assertJsonPath('data.*.id', [$id]);

        // No delete route: agents are deactivated.
        $this->deleteJson("/api/admin/mygram-agents/{$id}")->assertStatus(405);

        foreach ([$this->clerk, $this->accountsHead, User::factory()->admin()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->postJson('/api/admin/mygram-agents', ['name' => 'X', 'rl_number' => 'RL-X'])->assertForbidden();
        }
    });

    it('lists only active agents in the dropdown for the passport and accounts teams', function () {
        $active = agent();
        $inactive = agent('Old Agent', 'RL-OLD');
        $inactive->is_active = false;
        $inactive->save();

        foreach ([$this->clerk, $this->head, User::factory()->accounts()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/mygram-agents/lookup')->assertOk()->assertJsonPath('data.*.id', [$active->id]);
        }
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/mygram-agents/lookup')->assertForbidden();
    });
});

describe('assign and approve', function () {
    it('needs an approved agent before the medical slip', function () {
        $passport = agentPassport();
        $agent = agent();

        Sanctum::actingAs($this->clerk);
        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => '2026-04-20'])
            ->assertJsonValidationErrors(['medical_slip_date' => 'Assign a MYGRAM agent first. The Operations Head must approve it before the medical slip.']);

        assignAgent($passport, $agent, ['remark' => 'Sent today'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.agent.code', 'AG-001');

        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertJsonPath('data.agent_status', 'pending')
            ->assertJsonPath('data.mygram_agent', null)
            ->assertJsonPath('data.agent_pending.agent.id', $agent->id);

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => '2026-04-20'])
            ->assertJsonValidationErrors(['medical_slip_date' => 'MYGRAM agent not approved yet. The Operations Head must approve it before the medical slip.']);

        // One waiting at a time.
        assignAgent($passport, $agent)->assertJsonValidationErrors(['mygram_agent_id' => 'An agent is already waiting for approval on this passport.']);

        Sanctum::actingAs($this->head);
        $this->getJson('/api/operation-head/agent-approvals/summary')->assertJsonPath('data.pending', 1);
        $assignmentId = $this->getJson('/api/operation-head/agent-approvals')
            ->assertJsonPath('data.0.passport.id', $passport->id)
            ->assertJsonPath('data.0.can.approve', true)
            ->json('data.0.id');
        $this->postJson("/api/operation-head/agent-approvals/{$assignmentId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        Sanctum::actingAs($this->clerk);
        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertJsonPath('data.agent_status', 'approved')
            ->assertJsonPath('data.mygram_agent.rl_number', 'RL-1001');
        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => '2026-04-20'])->assertOk();
    });

    it('still lets a passport that had its slip before agents existed be edited', function () {
        $passport = PassportEntry::factory()->withMedicalSlip('2026-04-15')->create(['passport_received_date' => '2026-03-01']);
        Sanctum::actingAs($this->clerk);

        $this->patchJson("/api/data-entry/passports/{$passport->id}/medical-slip", ['medical_slip_date' => '2026-04-16'])->assertOk();
    });

    it('returns an assignment for correction, keeps it in the history, and takes a corrected one', function () {
        $passport = agentPassport();
        $wrong = agent();
        $right = agent('Right Agent', 'RL-2002');

        Sanctum::actingAs($this->clerk);
        assignAgent($passport, $wrong)->assertCreated();

        Sanctum::actingAs($this->head);
        $id = PassportAgentAssignment::query()->sole()->id;
        $this->postJson("/api/operation-head/agent-approvals/{$id}/return", [])
            ->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/operation-head/agent-approvals/{$id}/return", ['reason' => 'Wrong agent'])
            ->assertOk()->assertJsonPath('data.status', 'returned');

        Sanctum::actingAs($this->clerk);
        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertJsonPath('data.agent_status', 'returned')
            ->assertJsonPath('data.agent_return.reason', 'Wrong agent');

        assignAgent($passport, $right)->assertCreated();
        $this->getJson("/api/data-entry/passports/{$passport->id}/agent-assignments")
            ->assertJsonPath('data.*.status', ['returned', 'pending'])
            ->assertJsonPath('data.0.return_reason', 'Wrong agent');
    });

    it('lets nobody approve their own assignment, and only data entry assign', function () {
        $passport = agentPassport();
        $agent = agent();

        Sanctum::actingAs($this->head);
        assignAgent($passport, $agent)->assertForbidden();

        Sanctum::actingAs($this->clerk);
        assignAgent($passport, $agent)->assertCreated();
        $id = PassportAgentAssignment::query()->sole()->id;
        $this->postJson("/api/operation-head/agent-approvals/{$id}/approve")->assertForbidden();

        // An assignment the operations head made (e.g. while they had data entry access) stays theirs.
        PassportAgentAssignment::query()->whereKey($id)->update(['assigned_by' => $this->head->id]);
        Sanctum::actingAs($this->head);
        $this->postJson("/api/operation-head/agent-approvals/{$id}/approve")->assertForbidden();
    });

    it('checks the agent and the date', function () {
        $passport = agentPassport();
        $agent = agent();
        $inactive = agent('Off', 'RL-OFF');
        $inactive->is_active = false;
        $inactive->save();
        Sanctum::actingAs($this->clerk);

        assignAgent($passport, $agent, ['assigned_date' => '2026-05-02'])
            ->assertJsonValidationErrors(['assigned_date' => 'The assigned date cannot be in the future.']);
        assignAgent($passport, $agent, ['assigned_date' => '2026-02-01'])
            ->assertJsonValidationErrors(['assigned_date' => 'The assigned date cannot be before the passport was received.']);
        assignAgent($passport, $inactive)
            ->assertJsonValidationErrors(['mygram_agent_id' => 'Choose an active MYGRAM agent.']);
    });

    it('assigns and approves in bulk, skipping passports that already have an agent', function () {
        $agent = agent();
        $passports = collect([agentPassport(), agentPassport(), agentPassport()]);
        approveAgentFor($passports[2]);

        Sanctum::actingAs($this->clerk);
        $this->postJson('/api/data-entry/agent-assignments/bulk', [
            'passport_ids' => $passports->pluck('id')->all(),
            'mygram_agent_id' => $agent->id,
            'assigned_date' => '2026-04-01',
        ])
            ->assertOk()
            ->assertJsonCount(2, 'data.updated')
            ->assertJsonPath('data.skipped.0.id', $passports[2]->id)
            ->assertJsonPath('data.skipped.0.reason', 'This passport already has an agent. Use Change agent on the passport.');

        Sanctum::actingAs($this->head);
        $ids = PassportAgentAssignment::query()->where('status', 'pending')->pluck('id')->all();
        $this->postJson('/api/operation-head/agent-approvals/bulk-approve', ['ids' => $ids])
            ->assertOk()->assertJsonCount(2, 'data.updated')->assertJsonCount(0, 'data.skipped');

        $this->getJson("/api/data-entry/passports?mygram_agent_id={$agent->id}&medical_status=all")
            ->assertJsonCount(2, 'data');
    });
});

describe('changing the agent', function () {
    it('keeps the old agent until the change is approved, then asks the accounts head', function () {
        $passport = agentPassport();
        $a = agent('Agent A', 'RL-A');
        $b = agent('Agent B', 'RL-B');

        Sanctum::actingAs($this->clerk);
        assignAgent($passport, $a)->assertCreated();
        Sanctum::actingAs($this->head);
        $this->postJson('/api/operation-head/agent-approvals/'.PassportAgentAssignment::query()->sole()->id.'/approve')->assertOk();

        Sanctum::actingAs($this->clerk);
        assignAgent($passport, $a, ['remark' => 'x'])->assertJsonValidationErrors(['mygram_agent_id' => 'This passport is already with this agent.']);
        assignAgent($passport, $b)->assertJsonValidationErrors(['remark' => 'Enter the reason for changing the agent.']);
        assignAgent($passport, $b, ['assigned_date' => '2026-03-15', 'remark' => 'Slow'])->assertJsonValidationErrors(['assigned_date']);
        assignAgent($passport, $b, ['assigned_date' => '2026-04-10', 'remark' => 'Slow processing'])->assertCreated();

        // Still A until approved.
        $this->getJson("/api/data-entry/passports/{$passport->id}")
            ->assertJsonPath('data.mygram_agent.id', $a->id)
            ->assertJsonPath('data.agent_status', 'pending');

        Sanctum::actingAs($this->head);
        $this->getJson('/api/operation-head/agent-approvals')->assertJsonPath('data.0.current_agent.id', $a->id);
        $change = PassportAgentAssignment::query()->where('status', 'pending')->sole();
        $this->postJson("/api/operation-head/agent-approvals/{$change->id}/approve")->assertOk();

        $old = PassportAgentAssignment::query()->where('mygram_agent_id', $a->id)->sole();
        expect($old->replaced_by_id)->toBe($change->id)
            ->and($old->ended_date->toDateString())->toBe('2026-04-10')
            ->and($old->count_decision->value)->toBe('waiting');

        // The accounts head decides; until then it does not count for A.
        Sanctum::actingAs($this->accountsHead);
        $this->getJson('/api/agent-report')
            ->assertJsonPath('data.agents.0.counted', 0)
            ->assertJsonPath('data.agents.0.waiting_decision', 1)
            ->assertJsonPath('data.agents.1.counted', 1)
            ->assertJsonPath('data.agents.1.current', 1);
        $this->getJson('/api/accounts-head/agent-changes/summary')->assertJsonPath('data.waiting', 1);
        $this->getJson('/api/accounts-head/agent-changes')
            ->assertJsonPath('data.0.id', $old->id)
            ->assertJsonPath('data.0.replaced_by.agent.id', $b->id)
            ->assertJsonPath('data.0.replaced_by.remark', 'Slow processing');

        $this->postJson("/api/accounts-head/agent-changes/{$old->id}/decide", ['counts' => true, 'note' => 'A did the slip'])
            ->assertOk()->assertJsonPath('data.count_decision', 'yes');
        $this->getJson('/api/agent-report')->assertJsonPath('data.agents.0.counted', 1);

        $this->postJson("/api/accounts-head/agent-changes/{$old->id}/decide", ['counts' => false])->assertOk();
        $this->getJson('/api/agent-report')->assertJsonPath('data.agents.0.counted', 0);

        // Only the accounts head decides.
        Sanctum::actingAs($this->head);
        $this->postJson("/api/accounts-head/agent-changes/{$old->id}/decide", ['counts' => true])->assertForbidden();
    });
});

describe('passport entry list tabs', function () {
    it('counts passports without an approved agent and per agent', function () {
        $a = agent('Agent A', 'RL-A');
        $b = agent('Agent B', 'RL-B');
        $withA = [agentPassport(), agentPassport()];
        $pending = agentPassport();
        agentPassport();
        foreach ($withA as $passport) {
            PassportAgentAssignment::query()->create([
                'passport_entry_id' => $passport->id, 'mygram_agent_id' => $a->id,
                'assigned_date' => '2026-04-01', 'status' => 'approved',
            ]);
        }
        PassportAgentAssignment::query()->create([
            'passport_entry_id' => $pending->id, 'mygram_agent_id' => $b->id,
            'assigned_date' => '2026-04-01', 'status' => 'pending',
        ]);
        // A slipped passport (medical stage) is left out with stage=passport.
        $slipped = agentPassport();
        PassportAgentAssignment::query()->create([
            'passport_entry_id' => $slipped->id, 'mygram_agent_id' => $a->id,
            'assigned_date' => '2026-04-01', 'status' => 'approved',
        ]);
        Sanctum::actingAs($this->clerk);
        $this->patchJson("/api/data-entry/passports/{$slipped->id}/medical-slip", ['medical_slip_date' => '2026-04-15'])->assertOk();

        $this->getJson('/api/data-entry/agent-counts?stage=passport')
            ->assertOk()
            ->assertJsonPath('data.none', 2)
            ->assertJsonPath('data.approved', 2)
            ->assertJsonPath('data.agents.0.name', 'Agent A')
            ->assertJsonPath('data.agents.0.count', 2)
            ->assertJsonPath('data.agents.1.count', 0);
        $this->getJson('/api/data-entry/agent-counts')->assertJsonPath('data.approved', 3);

        $this->getJson("/api/data-entry/passports?stage=passport&mygram_agent_id={$a->id}")->assertJsonCount(2, 'data');
        $this->getJson('/api/data-entry/passports?stage=passport&mygram_agent_id=none')->assertJsonCount(2, 'data');

        Sanctum::actingAs(User::factory()->accounts()->create());
        $this->getJson('/api/data-entry/agent-counts')->assertForbidden();
    });
});

describe('agent report', function () {
    it('does not count returned or unfit passports, and counts them again when that is undone', function () {
        $agent = agent();
        $fitLater = agentPassport();
        $returned = agentPassport();
        $plain = agentPassport();
        foreach ([$fitLater, $returned, $plain] as $passport) {
            PassportAgentAssignment::query()->create([
                'passport_entry_id' => $passport->id, 'mygram_agent_id' => $agent->id,
                'assigned_date' => '2026-04-01', 'status' => 'approved',
            ]);
        }

        Sanctum::actingAs($this->head);
        $this->patchJson("/api/data-entry/passports/{$fitLater->id}/medical-slip", ['medical_slip_date' => '2026-04-15'])->assertOk();
        recordMedical($fitLater->id, '2026-04-20', 'unfit')->assertCreated();
        $this->postJson("/api/data-entry/passports/{$returned->id}/return", [
            'return_date' => '2026-04-30', 'receiver_name' => 'Owner', 'receiver_phone' => '01711000000',
        ])->assertSuccessful();

        $this->getJson('/api/agent-report')
            ->assertOk()
            ->assertJsonPath('data.agents.0.current', 3)
            ->assertJsonPath('data.agents.0.counted', 1)
            ->assertJsonPath('data.agents.0.buckets.unfit', 1)
            ->assertJsonPath('data.agents.0.buckets.returned', 1)
            ->assertJsonPath('data.agents.0.buckets.not_started', 1);

        $this->getJson("/api/agent-report/{$agent->id}/passports")
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('agent.rl_number', 'RL-1001');

        $this->deleteJson("/api/data-entry/passports/{$returned->id}/return")->assertSuccessful();
        $this->getJson('/api/agent-report')->assertJsonPath('data.agents.0.counted', 2);
    });

    it('shows passports without an agent, and is closed to data entry and accounts users', function () {
        agentPassport();
        Sanctum::actingAs($this->head);
        $this->getJson('/api/agent-report')->assertJsonPath('data.no_agent.current', 1);
        $this->getJson('/api/agent-report/none/passports')->assertJsonCount(1, 'data');

        foreach ([$this->clerk, User::factory()->accounts()->create(), User::factory()->admin()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/agent-report')->assertForbidden();
        }
    });
});

describe('agent payments', function () {
    it('pays an agent from Cash Payment and shows the ledger to the accounts team only', function () {
        $agent = agent();
        $passport = agentPassport();
        approveAgentFor($passport)->forceFill(['mygram_agent_id' => $agent->id])->save();

        Sanctum::actingAs(User::factory()->accounts()->create());
        fundAccount(50000);

        $payload = [
            'date' => '2026-10-10', 'payee_type' => 'agent', 'mygram_agent_id' => $agent->id,
            'payment_type' => 'agent_payment', 'amount' => 5000, 'payment_method' => 'bank',
            'bank_name' => 'City Bank', 'bank_branch' => 'Motijheel', 'bank_account_name' => 'Afwan', 'bank_account_number' => '123',
            'note' => 'Part payment for April',
        ];
        $this->postJson('/api/accounts/cash-payments', [...$payload, 'payment_type' => 'expense'])
            ->assertJsonValidationErrors(['payment_type']);
        $this->postJson('/api/accounts/cash-payments', [...$payload, 'mygram_agent_id' => null])
            ->assertJsonValidationErrors(['mygram_agent_id' => 'Choose the MYGRAM agent.']);
        $this->postJson('/api/accounts/cash-payments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.payee_type', 'agent')
            ->assertJsonPath('data.payee_name', 'Karim Overseas (AG-001)')
            ->assertJsonPath('data.agent.rl_number', 'RL-1001')
            ->assertJsonPath('data.party', null);

        $this->getJson("/api/accounts/mygram-agents/{$agent->id}")
            ->assertJsonPath('data.total_paid', 0)
            ->assertJsonPath('data.pending_amount', 5000)
            ->assertJsonPath('data.counted_passports', 1);

        Sanctum::actingAs($this->accountsHead);
        approveAllAccountsEntries();
        $this->postJson('/api/accounts/cash-payments', [...$payload, 'amount' => 2500])->assertCreated();
        approveAllAccountsEntries();

        $this->getJson("/api/accounts/mygram-agents/{$agent->id}")
            ->assertJsonPath('data.total_paid', 7500)
            ->assertJsonPath('data.average_per_passport', 7500)
            ->assertJsonPath('data.payments.1.running_total', 7500);
        $this->getJson('/api/accounts/mygram-agents')->assertJsonPath('data.0.total_paid', 7500);
        // The dashboard's paid total is split by what it was for; agent payments are their own part.
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'Asia/Dhaka')->utc());
        $this->getJson('/api/finance/summary')
            ->assertJsonPath('data.paid_breakdown.agent_payments', 7500)
            ->assertJsonPath('data.total_expenses', 0);
        $this->getJson("/api/accounts/cash-payments?mygram_agent_id={$agent->id}")->assertJsonCount(2, 'data');
        expect(CashPayment::query()->whereNotNull('party_id')->count())->toBe(0);

        foreach ([$this->head, $this->clerk] as $user) {
            Sanctum::actingAs($user);
            $this->getJson("/api/accounts/mygram-agents/{$agent->id}")->assertForbidden();
        }
    });
});
