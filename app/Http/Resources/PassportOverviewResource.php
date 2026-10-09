<?php

namespace App\Http\Resources;

use App\Models\PassportEntry;
use App\Support\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The read-only All Passports overview: passport details plus one latest
 * status. Deliberately leaves out medical details, workflow steps and action
 * data; the full PassportEntryResource has those.
 *
 * @mixin PassportEntry
 */
class PassportOverviewResource extends JsonResource
{
    /**
     * Relations to eager load before returning entries. currentMedical is only
     * read to tell an expired fit medical apart; its creator is not needed.
     *
     * @var list<string>
     */
    public const RELATIONS = ['country', 'reference', 'company.country', 'creator', 'currentMedical'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'passport_name' => $this->passport_name,
            'passport_number' => $this->passport_number,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'country' => $this->country ? [
                'code' => $this->country->code,
                'name' => $this->country->name,
            ] : null,
            'passport_expiry_date' => $this->passport_expiry_date?->toDateString(),
            'company' => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'country' => [
                    'code' => $this->company->country->code,
                    'name' => $this->company->country->name,
                ],
            ],
            'reference' => [
                'id' => $this->reference->id,
                'name' => $this->reference->name,
                'type' => $this->reference->type->value,
            ],
            'passport_received_date' => $this->passport_received_date->toDateString(),
            'entered_by' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
            'created_at' => $this->created_at,
            'latest_status' => app(Workflow::class)->latestStatus($this->resource),
        ];
    }
}
