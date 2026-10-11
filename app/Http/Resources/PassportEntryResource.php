<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\HasScanFields;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
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
    public const RELATIONS = ['country', 'reference', 'company.country', 'medicalCenter', 'currentMedical.creator', 'stepRecords.creator'];

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
            // Null only on old entries saved without a reference.
            'reference' => $this->reference ? [
                'id' => $this->reference->id,
                'type' => $this->reference->type->value,
                'type_label' => $this->reference->type->label(),
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
            // Where the medical is done (same as medical_slip.medical_center).
            'medical_center' => $this->medicalCenter ? [
                'id' => $this->medicalCenter->id,
                'name' => $this->medicalCenter->name,
            ] : null,
            'medical_status' => $medicalStatus->value,
            'medical_status_label' => $medicalStatus->label(),
            'current_medical' => $this->currentMedical ? MedicalRecordResource::summary($this->currentMedical) : null,
            'current_stage' => $this->current_stage,
            'stage_status' => $this->stage_status,
            'workflow' => $workflow->forPassport($this->resource, $medicalStatus),
            'warnings' => $workflow->warnings($this->resource),
            ...$this->scanFields(),
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // What the current user may do with this entry, for the SPA's buttons.
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'record_medical' => $request->user()?->can('create', [MedicalRecord::class, $this->resource]) ?? false,
            ],
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
