<?php

namespace App\Http\Resources;

use App\Models\PassportAgentAssignment;
use App\Support\AgentReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One MYGRAM agent assignment: a history row, an approval row (with the
 * passport) or an Agent Changes row (with the passport and the new agent).
 *
 * @mixin PassportAgentAssignment
 */
class AgentAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $person = fn (string $relation) => $this->whenLoaded($relation, fn () => $this->{$relation} ? ['id' => $this->{$relation}->id, 'name' => $this->{$relation}->name] : null);

        return [
            'id' => $this->id,
            'passport_entry_id' => $this->passport_entry_id,
            'agent' => $this->whenLoaded('agent', fn () => MygramAgentResource::summary($this->agent)),
            'assigned_date' => $this->assigned_date->toDateString(),
            'ended_date' => $this->ended_date?->toDateString(),
            'remark' => $this->remark,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_current' => $this->isCurrent(),
            'assigned_by' => $person('assigner'),
            'approved_by' => $person('approver'),
            'approved_at' => $this->approved_at,
            'returned_by' => $person('returner'),
            'returned_at' => $this->returned_at,
            'return_reason' => $this->return_reason,
            'count_decision' => $this->count_decision?->value,
            'count_decision_label' => $this->count_decision?->label(),
            'decision_note' => $this->decision_note,
            'decided_by' => $person('decider'),
            'decided_at' => $this->decided_at,
            // Whether the passport counts for this agent now (needs passportEntry).
            'counted' => $this->whenLoaded('passportEntry', fn () => $this->passportEntry ? AgentReport::counts($this->resource) : false),
            'passport' => $this->whenLoaded('passportEntry', fn () => $this->passportEntry ? [
                'id' => $this->passportEntry->id,
                'entry_no' => $this->passportEntry->entry_no,
                'passport_name' => $this->passportEntry->passport_name,
                'passport_number' => $this->passportEntry->passport_number,
                'medical_slip_date' => $this->passportEntry->medical_slip_date?->toDateString(),
                'bucket' => AgentReport::bucket($this->passportEntry),
            ] : null),
            // Approvals list: the agent the passport has now (a change replaces it).
            'current_agent' => $this->when(
                $this->relationLoaded('passportEntry') && $this->passportEntry?->relationLoaded('currentAgentAssignment'),
                fn () => ($current = $this->passportEntry->currentAgentAssignment) && $current->id !== $this->id && $current->relationLoaded('agent')
                    ? MygramAgentResource::summary($current->agent) : null,
            ),
            // Agent Changes list: the agent that replaced this one.
            'replaced_by' => $this->whenLoaded('replacedBy', fn () => $this->replacedBy ? [
                'id' => $this->replacedBy->id,
                'assigned_date' => $this->replacedBy->assigned_date->toDateString(),
                'remark' => $this->replacedBy->remark,
                'agent' => $this->replacedBy->relationLoaded('agent') ? MygramAgentResource::summary($this->replacedBy->agent) : null,
            ] : null),
            'can' => [
                'approve' => $request->user()?->can('approve', $this->resource) ?? false,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
