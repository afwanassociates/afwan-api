<?php

namespace App\Support;

use App\Enums\AgentAssignmentStatus;
use App\Exceptions\WorkflowException;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Models\RecruitmentAgent;

/**
 * Every passport goes through a recruitment agent (a partner or our Own agent):
 * no step moves forward (medical slip and result, calling, visa, BMET, flight)
 * until the operations head approved the passport's agent. Corrections (undo,
 * edit, clear) are not blocked.
 */
class AgentGate
{
    /**
     * The passport's current (approved, not replaced) agent, or null.
     */
    public static function currentAgent(PassportEntry $passport): ?RecruitmentAgent
    {
        return PassportAgentAssignment::query()
            ->where('passport_entry_id', $passport->id)
            ->current()
            ->with('agent')
            ->first()
            ?->agent;
    }

    /**
     * @throws WorkflowException when the passport has no approved agent
     */
    public static function assertApproved(PassportEntry $passport): RecruitmentAgent
    {
        $agent = static::currentAgent($passport);
        if ($agent !== null) {
            return $agent;
        }

        $pending = PassportAgentAssignment::query()
            ->where('passport_entry_id', $passport->id)
            ->where('status', AgentAssignmentStatus::Pending->value)
            ->exists();

        throw $pending ? WorkflowException::agentNotApproved() : WorkflowException::agentMissing();
    }
}
