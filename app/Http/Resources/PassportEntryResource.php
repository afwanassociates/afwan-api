<?php

namespace App\Http\Resources;

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
    /**
     * Relations to eager load before returning entries.
     *
     * @var list<string>
     */
    public const RELATIONS = ['country', 'reference', 'company.country', 'currentMedical.creator', 'currentMedical.medicalCenter', 'stepRecords.creator'];

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
            'reference' => [
                'id' => $this->reference->id,
                'type' => $this->reference->type->value,
                'type_label' => $this->reference->type->label(),
                'name' => $this->reference->name,
            ],
            'company' => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'country' => [
                    'code' => $this->company->country->code,
                    'name' => $this->company->country->name,
                ],
            ],
            'medical_status' => $medicalStatus->value,
            'medical_status_label' => $medicalStatus->label(),
            'current_medical' => $this->currentMedical ? MedicalRecordResource::summary($this->currentMedical) : null,
            'current_stage' => $this->current_stage,
            'stage_status' => $this->stage_status,
            'workflow' => $workflow->forPassport($this->resource, $medicalStatus),
            'warnings' => $workflow->warnings($this->resource),
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
}
