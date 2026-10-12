<?php

namespace App\Http\Controllers\DataEntry;

use App\Enums\MedicalResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\StoreMedicalResultRequest;
use App\Http\Requests\DataEntry\UpdateMedicalRecordRequest;
use App\Http\Resources\MedicalRecordResource;
use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use App\Services\MedicalService;
use App\Support\LocalDate;
use App\Support\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class MedicalRecordController extends Controller
{
    public function __construct(protected MedicalService $medicals, protected Workflow $workflow) {}

    /**
     * A passport's full medical history, newest first.
     */
    public function index(PassportEntry $passport): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', MedicalRecord::class);

        $records = $passport->medicalRecords()->with(MedicalRecordResource::RELATIONS)->get();

        // The permission checks read the passport's stage: share the one instance.
        $records->each->setRelation('passportEntry', $passport);

        return MedicalRecordResource::collection($records);
    }

    /**
     * Record the result (fit or unfit) for the passport's medical date. A
     * re-test adds a new record after a new medical date; history is kept.
     */
    public function result(StoreMedicalResultRequest $request, PassportEntry $passport): JsonResponse
    {
        $passport->load('currentMedical');
        Gate::authorize('create', [MedicalRecord::class, $passport]);

        $record = $this->medicals->recordResult(
            $passport,
            MedicalResult::from($request->validated('result')),
            $request->user(),
            $request->validated('issue_date'),
            $request->validated('expiry_date'),
        );

        return $this->respond($record, $passport)->setStatusCode(201);
    }

    /**
     * Correct a medical record's date, result or remarks.
     */
    public function update(UpdateMedicalRecordRequest $request, MedicalRecord $medical): JsonResponse
    {
        Gate::authorize('update', $medical);

        $record = $this->medicals->update($medical, $request->validated(), $request->user());

        return $this->respond($record, $record->passportEntry()->withTrashed()->firstOrFail());
    }

    /**
     * Soft delete a medical record, recording who deleted it.
     */
    public function destroy(Request $request, MedicalRecord $medical): Response
    {
        Gate::authorize('delete', $medical);

        $this->medicals->delete($medical, $request->user());

        return response()->noContent();
    }

    /**
     * The record, the passport's updated medical status and step bar, and any
     * warnings about the dates (warnings never block saving).
     */
    protected function respond(MedicalRecord $record, PassportEntry $passport): JsonResponse
    {
        $record->load(MedicalRecordResource::RELATIONS)->setRelation('passportEntry', $passport);
        $passport->load(['currentMedical', 'stepRecords.creator']);
        $status = $passport->medicalStatus();

        return (new MedicalRecordResource($record))
            ->additional([
                'passport' => [
                    'id' => $passport->id,
                    'medical_status' => $status->value,
                    'medical_status_label' => $status->label(),
                    'current_medical_id' => $passport->current_medical_id,
                    'current_stage' => $passport->current_stage,
                    'stage_status' => $passport->stage_status,
                    'workflow' => $this->workflow->forPassport($passport, $status),
                ],
                'warnings' => $this->warnings($record, $passport),
            ])
            ->response();
    }

    /**
     * Date problems worth a second look, as code + message pairs.
     *
     * @return list<array{code: string, message: string}>
     */
    protected function warnings(MedicalRecord $record, PassportEntry $passport): array
    {
        $warnings = [];
        $medicalDate = $record->medical_date->toDateString();
        $expiry = $passport->passport_expiry_date?->toDateString();

        if ($medicalDate < $passport->passport_received_date->toDateString()) {
            $warnings[] = [
                'code' => 'medical_before_received',
                'message' => 'The medical date is before the passport received date.',
            ];
        }

        if ($expiry !== null && $expiry < LocalDate::today()->toDateString()) {
            $warnings[] = [
                'code' => 'passport_expired',
                'message' => 'The passport has already expired.',
            ];
        } elseif ($expiry !== null && $record->valid_until !== null && $expiry < $record->valid_until->toDateString()) {
            $warnings[] = [
                'code' => 'passport_expires_before_medical',
                'message' => 'The passport expires before the medical valid-until date.',
            ];
        }

        return $warnings;
    }
}
