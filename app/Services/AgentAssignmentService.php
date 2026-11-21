<?php

namespace App\Services;

use App\Enums\AgentAssignmentStatus;
use App\Enums\AgentCountDecision;
use App\Models\PassportActivity;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Models\RecruitmentAgent;
use App\Models\User;
use App\Support\LocalDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every recruitment agent assignment write goes through here.
 *
 *   data entry assigns (or changes) the agent   → pending
 *   the operations head approves                → approved (the current agent)
 *                       or returns for correction → returned (kept in the history)
 *
 * Approving a change ends the old agent's assignment and asks the accounts head
 * whether the passport still counts for the old agent (count_decision waiting).
 *   the operations head undoes an approval      → pending again (approve or return it)
 *
 * Nothing is ever deleted.
 */
class AgentAssignmentService
{
    /**
     * Send an assignment (or a change of agent) for approval.
     *
     * @param  array{recruitment_agent_id: int, assigned_date: string, remark?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function assign(PassportEntry $passport, array $data, User $user, bool $firstOnly = false): PassportAgentAssignment
    {
        return DB::transaction(function () use ($passport, $data, $user, $firstOnly) {
            $locked = PassportEntry::query()->lockForUpdate()->findOrFail($passport->id);

            if ($locked->isReturned()) {
                $this->fail('recruitment_agent_id', 'This passport is returned to its owner.');
            }

            $pending = PassportAgentAssignment::query()
                ->where('passport_entry_id', $locked->id)
                ->where('status', AgentAssignmentStatus::Pending->value)
                ->exists();
            if ($pending) {
                $this->fail('recruitment_agent_id', 'An agent is already waiting for approval on this passport.');
            }

            $agent = RecruitmentAgent::query()->find($data['recruitment_agent_id']);
            if (! $agent || ! $agent->is_active) {
                $this->fail('recruitment_agent_id', 'Choose an active recruitment agent.');
            }

            $date = $data['assigned_date'];
            if ($date > LocalDate::today()->toDateString()) {
                $this->fail('assigned_date', 'The assigned date cannot be in the future.');
            }
            if ($date < $locked->passport_received_date->toDateString()) {
                $this->fail('assigned_date', 'The assigned date cannot be before the passport was received.');
            }

            $current = PassportAgentAssignment::query()
                ->where('passport_entry_id', $locked->id)
                ->current()
                ->first();
            $remark = $data['remark'] ?? null;

            if ($current) {
                if ($firstOnly) {
                    $this->fail('recruitment_agent_id', 'This passport already has an agent. Use Change agent on the passport.');
                }
                if ($current->recruitment_agent_id === $agent->id) {
                    $this->fail('recruitment_agent_id', 'This passport is already with this agent.');
                }
                if ($remark === null || $remark === '') {
                    $this->fail('remark', 'Enter the reason for changing the agent.');
                }
                if ($date < $current->assigned_date->toDateString()) {
                    $this->fail('assigned_date', 'The new agent\'s date cannot be before the current agent\'s date ('.$current->assigned_date->format('d/m/Y').').');
                }
            }

            $assignment = PassportAgentAssignment::query()->create([
                'passport_entry_id' => $locked->id,
                'recruitment_agent_id' => $agent->id,
                'assigned_date' => $date,
                'remark' => $remark,
                'status' => AgentAssignmentStatus::Pending,
                'assigned_by' => $user->id,
            ]);
            $this->log($assignment, $current ? 'agent_change' : 'agent_assign', $current ? 'Agent change sent for approval' : 'Agent assigned (waiting for approval)', $user, $remark);

            return $assignment;
        });
    }

    /**
     * Approve a pending assignment: it becomes the current agent. A change ends
     * the old agent's assignment, which then waits for the accounts head.
     *
     * @throws ValidationException
     */
    public function approve(PassportAgentAssignment $assignment, User $user): PassportAgentAssignment
    {
        return DB::transaction(function () use ($assignment, $user) {
            PassportEntry::query()->lockForUpdate()->findOrFail($assignment->passport_entry_id);
            $locked = $this->lockedPending($assignment, $user);

            $old = PassportAgentAssignment::query()
                ->where('passport_entry_id', $locked->passport_entry_id)
                ->current()
                ->first();

            $locked->forceFill([
                'status' => AgentAssignmentStatus::Approved,
                'approved_by' => $user->id,
                'approved_at' => now(),
            ])->save();

            if ($old) {
                $old->forceFill([
                    'replaced_by_id' => $locked->id,
                    'ended_date' => $locked->assigned_date,
                    'count_decision' => AgentCountDecision::Waiting,
                ])->save();
            }
            $this->log($locked, 'agent_approve', 'Agent approved', $user);

            return $locked;
        });
    }

    /**
     * Why the passport's current agent approval can't be undone now, or null when it can.
     * The first agent only until the medical slip is issued (every step after that needs an
     * approved agent); an agent change until the accounts head has decided about the old agent.
     */
    public function undoApprovalBlocker(PassportEntry $passport, ?PassportAgentAssignment $current): ?string
    {
        if ($current === null || ! $current->isCurrent()) {
            return 'This passport has no approved agent.';
        }
        if ($passport->isReturned()) {
            return 'This passport is returned to its owner.';
        }

        $old = PassportAgentAssignment::query()->where('replaced_by_id', $current->id)->first();
        if ($old === null) {
            return $passport->medical_slip_date !== null
                ? 'The medical slip is already issued with this agent. Change the agent instead.'
                : null;
        }

        return $old->count_decision !== AgentCountDecision::Waiting
            ? 'The accounts head has already decided about the previous agent. Change the agent instead.'
            : null;
    }

    /**
     * Undo the approval of the passport's current agent: it goes back to "waiting for
     * approval" (the operations head approves it again or returns it for correction). An
     * agent change gives the passport back to the previous agent until then.
     *
     * @throws ValidationException
     */
    public function undoApproval(PassportEntry $passport, User $user): PassportAgentAssignment
    {
        return DB::transaction(function () use ($passport, $user) {
            $locked = PassportEntry::query()->lockForUpdate()->findOrFail($passport->id);
            $current = PassportAgentAssignment::query()
                ->where('passport_entry_id', $locked->id)
                ->current()
                ->lockForUpdate()
                ->first();

            if ($reason = $this->undoApprovalBlocker($locked, $current)) {
                $this->fail('assignment', $reason);
            }

            PassportAgentAssignment::query()->where('replaced_by_id', $current->id)->update([
                'replaced_by_id' => null,
                'ended_date' => null,
                'count_decision' => null,
            ]);

            $current->forceFill([
                'status' => AgentAssignmentStatus::Pending,
                'approved_by' => null,
                'approved_at' => null,
                'approval_undone_by' => $user->id,
                'approval_undone_at' => now(),
            ])->save();
            $this->log($current, 'agent_approve_undo', 'Agent approval undone (waiting for approval)', $user);

            return $current;
        });
    }

    /**
     * Return a pending assignment to data entry for correction (reason required).
     *
     * @throws ValidationException
     */
    public function sendBack(PassportAgentAssignment $assignment, User $user, string $reason): PassportAgentAssignment
    {
        return DB::transaction(function () use ($assignment, $user, $reason) {
            $locked = $this->lockedPending($assignment, $user);

            $locked->forceFill([
                'status' => AgentAssignmentStatus::Returned,
                'returned_by' => $user->id,
                'returned_at' => now(),
                'return_reason' => $reason,
            ])->save();
            $this->log($locked, 'agent_return', 'Agent returned for correction', $user, $reason);

            return $locked;
        });
    }

    /**
     * The accounts head's answer for a replaced assignment: does the passport
     * still count (is paid) for the old agent? Can be changed later.
     *
     * @throws ValidationException
     */
    public function decide(PassportAgentAssignment $assignment, bool $counts, ?string $note, User $user): PassportAgentAssignment
    {
        return DB::transaction(function () use ($assignment, $counts, $note, $user) {
            $locked = PassportAgentAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($locked->count_decision === null) {
                $this->fail('decision', 'Only an agent that was changed needs this decision.');
            }

            $locked->forceFill([
                'count_decision' => $counts ? AgentCountDecision::Yes : AgentCountDecision::No,
                'decision_note' => $note,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Run one action for many ids, skipping (with the reason) those that fail.
     *
     * @param  list<int>  $ids
     * @param  callable(int): array<string, mixed>  $apply  returns the row of an updated id
     * @return array{updated: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function bulk(array $ids, callable $apply, callable $describe): array
    {
        $updated = [];
        $skipped = [];

        foreach (array_values(array_unique($ids)) as $id) {
            try {
                $updated[] = $apply($id);
            } catch (ValidationException $e) {
                $skipped[] = [...$describe($id), 'reason' => collect($e->errors())->flatten()->first() ?? $e->getMessage()];
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * The assignment's action in the passport history.
     */
    private function log(PassportAgentAssignment $assignment, string $action, string $title, User $user, ?string $remarks = null): void
    {
        $agent = $assignment->agent()->first();

        PassportActivity::record($assignment->passport_entry_id, 'passport', $action, $title, [
            ['label' => 'Agent', 'value' => $agent?->name],
            ['label' => 'RL number', 'value' => $agent?->rl_number],
            ['label' => 'Assigned date', 'value' => $assignment->assigned_date?->toDateString(), 'type' => 'date'],
        ], $user, $remarks);
    }

    /**
     * @throws ValidationException
     */
    private function lockedPending(PassportAgentAssignment $assignment, User $user): PassportAgentAssignment
    {
        $locked = PassportAgentAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

        if ($locked->status !== AgentAssignmentStatus::Pending) {
            $this->fail('assignment', 'This agent assignment is not waiting for approval any more.');
        }
        if ($locked->assigned_by === $user->id) {
            $this->fail('assignment', 'You cannot approve or return an agent assignment you made yourself.');
        }

        return $locked;
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
