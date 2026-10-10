<?php

namespace App\Http\Resources;

use App\Models\PassportStepRecord;
use App\Support\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PassportStepRecord
 */
class PassportStepRecordResource extends JsonResource
{
    /**
     * Relations to eager load before returning records (set passportEntry on
     * each record too, for the permission checks).
     *
     * @var list<string>
     */
    public const RELATIONS = ['creator'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $workflow = app(Workflow::class);

        return [
            ...$workflow->recordSummary($this->resource),
            'passport_entry_id' => $this->passport_entry_id,
            'step_key' => $this->step_key,
            'step_label' => $workflow->label($this->step_key),
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // What the current user may do with this record, for the SPA's buttons.
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
