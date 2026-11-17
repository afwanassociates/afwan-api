<?php

namespace App\Support;

use App\Enums\AgentAssignmentStatus;
use App\Enums\AgentCountDecision;
use App\Models\MygramAgent;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use Illuminate\Support\Collection;

/**
 * The MYGRAM agent report: per agent, the passports it has now, the ones that
 * count for it (are paid) and where its passports are in the workflow.
 *
 * A passport counts for an agent when the assignment is the current one, or a
 * replaced one the accounts head said Yes to, and the passport is neither
 * returned to its owner nor unfit. Undoing the return or a fit re-test makes it
 * count again (nothing is stored: it is worked out from the passport each time).
 */
class AgentReport
{
    /** Where a passport is, in report order. */
    public const BUCKETS = ['not_started', 'medical_pending', 'fit', 'unfit', 'calling_onwards', 'returned'];

    /**
     * The bucket of a passport (from its stage, kept by WorkflowService::recompute).
     */
    public static function bucket(PassportEntry $passport): string
    {
        if ($passport->isReturned()) {
            return 'returned';
        }

        return match (true) {
            $passport->current_stage === Workflow::PASSPORT => 'not_started',
            $passport->current_stage === 'medical' && $passport->stage_status === 'pending' => 'medical_pending',
            $passport->current_stage === 'medical' && $passport->stage_status === 'fit' => 'fit',
            $passport->current_stage === 'medical' && $passport->stage_status === 'unfit' => 'unfit',
            default => 'calling_onwards',
        };
    }

    /**
     * Whether the assignment counts for its agent now.
     */
    public static function counts(PassportAgentAssignment $assignment): bool
    {
        if ($assignment->status !== AgentAssignmentStatus::Approved) {
            return false;
        }
        if ($assignment->replaced_by_id !== null && $assignment->count_decision !== AgentCountDecision::Yes) {
            return false;
        }

        return ! in_array(self::bucket($assignment->passportEntry), ['returned', 'unfit'], true);
    }

    /**
     * Counted passports per agent id.
     *
     * @return array<int, int>
     */
    public function countedByAgent(?int $agentId = null): array
    {
        return $this->assignments($agentId)
            ->filter(fn (PassportAgentAssignment $a) => self::counts($a))
            ->countBy('mygram_agent_id')
            ->all();
    }

    /**
     * One row per agent (active first, then by code) plus the "No agent" row.
     *
     * @return array{agents: list<array<string, mixed>>, no_agent: array<string, mixed>}
     */
    public function rows(): array
    {
        $assignments = $this->assignments()->groupBy('mygram_agent_id');

        $agents = MygramAgent::query()->orderByDesc('is_active')->orderBy('code')->get()
            ->map(function (MygramAgent $agent) use ($assignments) {
                /** @var Collection<int, PassportAgentAssignment> $rows */
                $rows = $assignments->get($agent->id, collect());
                $current = $rows->filter(fn (PassportAgentAssignment $a) => $a->isCurrent());

                return [
                    'id' => $agent->id,
                    'code' => $agent->code,
                    'name' => $agent->name,
                    'rl_number' => $agent->rl_number,
                    'is_active' => $agent->is_active,
                    'current' => $current->count(),
                    'counted' => $rows->filter(fn (PassportAgentAssignment $a) => self::counts($a))->count(),
                    'waiting_approval' => $rows->where('status', AgentAssignmentStatus::Pending)->count(),
                    'waiting_decision' => $rows->where('count_decision', AgentCountDecision::Waiting)->count(),
                    'buckets' => $this->buckets($current->map(fn (PassportAgentAssignment $a) => $a->passportEntry)),
                ];
            })->values()->all();

        $withoutAgent = PassportEntry::query()
            ->whereDoesntHave('currentAgentAssignment')
            ->withExists('pendingAgentAssignment as has_pending_agent')
            ->get(['id', 'current_stage', 'stage_status', 'current_return_id']);

        return [
            'agents' => $agents,
            'no_agent' => [
                'current' => $withoutAgent->count(),
                'waiting_approval' => $withoutAgent->where('has_pending_agent', true)->count(),
                'buckets' => $this->buckets($withoutAgent),
            ],
        ];
    }

    /**
     * Every passport an agent has or had, newest assignment first, with its bucket
     * and whether it counts. "No agent" (agent null) lists passports without a
     * current agent.
     *
     * @return list<array<string, mixed>>
     */
    public function passports(?MygramAgent $agent): array
    {
        if ($agent === null) {
            return PassportEntry::query()
                ->whereDoesntHave('currentAgentAssignment')
                ->with(['reference', 'company', 'pendingAgentAssignment.agent', 'latestAgentAssignment'])
                ->orderBy('entry_no')
                ->get()
                ->map(fn (PassportEntry $p) => [
                    ...$this->passportRow($p),
                    'assignment' => null,
                    'agent_status' => $p->pendingAgentAssignment ? 'pending' : ($p->latestAgentAssignment?->status === AgentAssignmentStatus::Returned ? 'returned' : 'none'),
                    'pending_agent' => $p->pendingAgentAssignment?->agent?->name,
                ])->all();
        }

        return $agent->assignments()
            ->where('status', '<>', AgentAssignmentStatus::Returned->value)
            ->with(['passportEntry.reference', 'passportEntry.company'])
            ->whereHas('passportEntry')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PassportAgentAssignment $a) => [
                ...$this->passportRow($a->passportEntry),
                'assignment' => [
                    'id' => $a->id,
                    'status' => $a->status->value,
                    'assigned_date' => $a->assigned_date->toDateString(),
                    'ended_date' => $a->ended_date?->toDateString(),
                    'is_current' => $a->isCurrent(),
                    'count_decision' => $a->count_decision?->value,
                    'counted' => self::counts($a),
                ],
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function passportRow(PassportEntry $p): array
    {
        return [
            'id' => $p->id,
            'entry_no' => $p->entry_no,
            'passport_name' => $p->passport_name,
            'passport_number' => $p->passport_number,
            'reference' => $p->reference?->name,
            'company' => $p->company?->name,
            'bucket' => self::bucket($p),
        ];
    }

    /**
     * @param  Collection<int, PassportEntry>  $passports
     * @return array<string, int>
     */
    private function buckets(Collection $passports): array
    {
        $counts = array_fill_keys(self::BUCKETS, 0);

        foreach ($passports as $passport) {
            $counts[self::bucket($passport)]++;
        }

        return $counts;
    }

    /**
     * Assignments (not returned ones) with the passport fields the buckets need.
     * Deleted passports are left out.
     *
     * @return Collection<int, PassportAgentAssignment>
     */
    private function assignments(?int $agentId = null): Collection
    {
        return PassportAgentAssignment::query()
            ->where('status', '<>', AgentAssignmentStatus::Returned->value)
            ->when($agentId, fn ($q, int $id) => $q->where('mygram_agent_id', $id))
            ->whereHas('passportEntry')
            ->with('passportEntry:id,current_stage,stage_status,current_return_id')
            ->get();
    }
}
