<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\HasScanFields;
use App\Models\MedicalRecord;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Services\AgentAssignmentService;
use App\Support\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PassportEntry
 */
class PassportEntryResource extends JsonResource
{
    use HasScanFields;

    /**
     * Relations to eager load before returning entries.
     *
     * @var list<string>
     */
    public const RELATIONS = ['country', 'reference', 'company.country', 'medicalCenter', 'creator', 'currentMedical.creator', 'stepRecords.creator', 'currentReturn.returner', ...self::AGENT_RELATIONS];

    /**
     * The recruitment agent relations (current, pending and newest assignment).
     *
     * @var list<string>
     */
    public const AGENT_RELATIONS = ['currentAgentAssignment.agent', 'pendingAgentAssignment.agent', 'latestAgentAssignment'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $medicalStatus = $this->medicalStatus();
        $workflow = app(Workflow::class);

        return [
            'id' => $this->id,
            // The serial number shown as "No." (1, 2, 3, …).
            'entry_no' => $this->entry_no,
            'passport_name' => $this->passport_name,
            'passport_number' => $this->passport_number,
            // Inactive countries still resolve, so old entries keep their names.
            'country' => $this->country ? [
                'code' => $this->country->code,
                'name' => $this->country->name,
            ] : null,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'passport_received_date' => $this->passport_received_date->toDateString(),
            'passport_expiry_date' => $this->passport_expiry_date?->toDateString(),
            // The personal data page (null on old entries).
            'father_name' => $this->father_name,
            'mother_name' => $this->mother_name,
            'permanent_address' => $this->permanent_address,
            // Null only on old entries saved without a reference.
            'reference' => $this->reference ? [
                'id' => $this->reference->id,
                'type' => $this->reference->kind?->value,
                'type_label' => $this->reference->kind?->label(),
                'name' => $this->reference->name,
            ] : null,
            'company' => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'country' => [
                    'code' => $this->company->country->code,
                    'name' => $this->company->country->name,
                ],
            ],
            // The medical slip ("MYGRAM"); the medical result form pre-fills from it.
            'medical_slip' => [
                'date' => $this->medical_slip_date?->toDateString(),
                'medical_center' => $this->medicalCenter ? [
                    'id' => $this->medicalCenter->id,
                    'name' => $this->medicalCenter->name,
                ] : null,
            ],
            // The date the passport goes (or went) for its medical; null until entered.
            'medical_date' => $this->medical_date?->toDateString(),
            'medical_slip_date' => $this->medical_slip_date?->toDateString(),
            // The current result's issue and expiry dates (null until fit).
            'issue_date' => $this->currentMedical?->issue_date?->toDateString(),
            'valid_until' => $this->currentMedical?->valid_until?->toDateString(),
            // The Online mark: a fit passport needs it before Calling.
            'entry_mode' => $this->entry_mode ? ['value' => $this->entry_mode->value, 'label' => $this->entry_mode->label()] : null,
            'has_calling_record' => $this->hasStepRecords(),
            // Where the medical is done (same as medical_slip.medical_center).
            'medical_center' => $this->medicalCenter ? [
                'id' => $this->medicalCenter->id,
                'name' => $this->medicalCenter->name,
            ] : null,
            // The recruitment agent: the approved one, and what is waiting or was returned.
            ...$this->agentFields(),
            'medical_status' => $medicalStatus->value,
            'medical_status_label' => $medicalStatus->label(),
            'current_medical' => $this->currentMedical ? MedicalRecordResource::summary($this->currentMedical) : null,
            'current_stage' => $this->current_stage,
            'stage_status' => $this->stage_status,
            // One line for the stage and status, e.g. "Ready for Calling (Online)".
            'latest_status' => $workflow->latestStatus($this->resource),
            'workflow' => $workflow->forPassport($this->resource, $medicalStatus),
            'warnings' => $workflow->warnings($this->resource),
            // Set while the passport is returned to its owner (out of every working list).
            'returned' => $this->currentReturn ? ReturnedPassportResource::summary($this->currentReturn) : null,
            ...$this->scanFields(),
            'created_by' => $this->created_by,
            // Who entered it, by name ("Entered by" in the Passport Entry List).
            'entered_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // What the current user may do with this entry, for the SPA's buttons.
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'record_medical' => $request->user()?->can('create', [MedicalRecord::class, $this->resource]) ?? false,
                'return' => $request->user()?->can('returnPassport', $this->resource) ?? false,
                'undo_return' => $request->user()?->can('undoReturn', $this->resource) ?? false,
                'assign_agent' => $request->user()?->can('assign', [PassportAgentAssignment::class, $this->resource]) ?? false,
                'cancel_medical_result' => $request->user()?->can('cancelMedicalResult', $this->resource) ?? false,
                'undo_agent_approval' => $this->canUndoAgentApproval($request),
            ],
        ];
    }

    /**
     * The operations head can undo the current agent's approval (passport page only).
     */
    protected function canUndoAgentApproval(Request $request): bool
    {
        if (! $this->relationLoaded('currentAgentAssignment') || ! ($request->user()?->can('undoApproval', [PassportAgentAssignment::class, $this->resource]) ?? false)) {
            return false;
        }

        return app(AgentAssignmentService::class)->undoApprovalBlocker($this->resource, $this->currentAgentAssignment) === null;
    }

    /**
     * recruitment_agent (approved, null when none), agent_status (none, pending,
     * returned, approved), the pending assignment and the newest return reason.
     *
     * @return array<string, mixed>
     */
    protected function agentFields(): array
    {
        if (! $this->relationLoaded('currentAgentAssignment')) {
            return [];
        }

        $current = $this->currentAgentAssignment;
        $pending = $this->pendingAgentAssignment;
        $latest = $this->latestAgentAssignment;
        $status = $this->resource->agentStatus();

        return [
            'recruitment_agent' => $current ? [
                ...RecruitmentAgentResource::summary($current->agent),
                'assigned_date' => $current->assigned_date->toDateString(),
            ] : null,
            'agent_status' => $status,
            'agent_pending' => $pending ? [
                'id' => $pending->id,
                'agent' => RecruitmentAgentResource::summary($pending->agent),
                'assigned_date' => $pending->assigned_date->toDateString(),
                'remark' => $pending->remark,
            ] : null,
            'agent_return' => $status === 'returned' ? [
                'id' => $latest->id,
                'recruitment_agent_id' => $latest->recruitment_agent_id,
                'assigned_date' => $latest->assigned_date->toDateString(),
                'remark' => $latest->remark,
                'reason' => $latest->return_reason,
            ] : null,
        ];
    }

    /**
     * The scan copy: every field on a details response (lists override this).
     *
     * @return array<string, mixed>
     */
    protected function scanFields(): array
    {
        return $this->scanDetails($this->resource);
    }
}
