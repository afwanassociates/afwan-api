<?php

namespace App\Http\Resources;

use App\Enums\StepStatus;
use App\Models\PassportReturn;
use App\Support\Workflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A passport return: who collected the passport, when, and the step it was at.
 * summary() is the shape embedded in a passport (its "returned" field).
 *
 * @mixin PassportReturn
 */
class ReturnedPassportResource extends JsonResource
{
    /**
     * Relations to eager load for the Returned Passports list.
     *
     * @var list<string>
     */
    public const RELATIONS = ['returner', 'passportEntry.reference', 'passportEntry.company'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $passport = $this->passportEntry;

        return [
            ...self::summary($this->resource),
            'passport' => [
                'id' => $passport->id,
                'entry_no' => $passport->entry_no,
                'passport_name' => $passport->passport_name,
                'passport_number' => $passport->passport_number,
            ],
            'reference' => $passport->reference ? ['id' => $passport->reference->id, 'name' => $passport->reference->name] : null,
            'company' => ['id' => $passport->company->id, 'name' => $passport->company->name],
            'can_undo' => $request->user()?->can('undoReturn', $passport) ?? false,
        ];
    }

    /**
     * The return's own fields (load returner first to avoid a query).
     *
     * @return array<string, mixed>
     */
    public static function summary(PassportReturn $return): array
    {
        $workflow = app(Workflow::class);

        return [
            'id' => $return->id,
            'return_date' => $return->return_date->toDateString(),
            'receiver_name' => $return->receiver_name,
            'receiver_phone' => $return->receiver_phone,
            'receiver_id_no' => $return->receiver_id_no,
            'remarks' => $return->remarks,
            'stage' => $return->stage,
            'stage_label' => self::stageLabel($workflow, $return->stage),
            'returned_by' => $return->returner ? ['id' => $return->returner->id, 'name' => $return->returner->name] : null,
            'returned_at' => $return->created_at?->toIso8601String(),
        ];
    }

    /**
     * The step the passport was at, e.g. "Visa", or "Flight confirmed" once completed.
     */
    public static function stageLabel(Workflow $workflow, string $stage): string
    {
        if ($stage !== Workflow::COMPLETED) {
            return $workflow->label($stage);
        }

        $steps = $workflow->recordStepKeys();
        $last = $steps[count($steps) - 1] ?? null;

        return $last ? StepStatus::Completed->label($last) : $workflow->statusLabel(Workflow::COMPLETED);
    }
}
