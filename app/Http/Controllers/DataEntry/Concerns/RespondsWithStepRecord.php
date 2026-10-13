<?php

namespace App\Http\Controllers\DataEntry\Concerns;

use App\Http\Resources\PassportStepRecordResource;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use Illuminate\Http\JsonResponse;

/**
 * The response to saving a step record, shared by every step action. Needs a
 * $workflow (App\Support\Workflow) property.
 */
trait RespondsWithStepRecord
{
    /**
     * The record, the passport's new stage and step bar, and any warnings
     * (warnings never block saving).
     */
    protected function respond(PassportStepRecord $record, PassportEntry $passport): JsonResponse
    {
        $passport->load(['currentMedical', 'stepRecords.creator']);
        $record->load(PassportStepRecordResource::RELATIONS)->setRelation('passportEntry', $passport);

        return (new PassportStepRecordResource($record))
            ->additional($this->passportPayload($passport))
            ->response();
    }

    /**
     * The passport's new stage, step bar and warnings.
     *
     * @return array{passport: array<string, mixed>, warnings: list<array<string, mixed>>}
     */
    protected function passportPayload(PassportEntry $passport): array
    {
        $passport->loadMissing(['currentMedical', 'stepRecords.creator']);

        return [
            'passport' => [
                'id' => $passport->id,
                'current_stage' => $passport->current_stage,
                'stage_status' => $passport->stage_status,
                'medical_status' => $passport->medicalStatus()->value,
                'workflow' => $this->workflow->forPassport($passport),
            ],
            'warnings' => $this->workflow->warnings($passport),
        ];
    }
}
