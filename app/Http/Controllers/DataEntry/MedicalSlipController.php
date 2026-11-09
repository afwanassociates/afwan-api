<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\UpdateMedicalSlipRequest;
use App\Http\Resources\PassportEntryResource;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use App\Services\MedicalService;
use Illuminate\Support\Facades\Gate;

class MedicalSlipController extends Controller
{
    /**
     * Save the passport's medical slip (date and centre). Entering the
     * date moves the passport from not_started into Medical Pending.
     * Clearing it is the undo of that (back to the Passport Entry list): the
     * operations head only, like the other undos.
     */
    public function __invoke(UpdateMedicalSlipRequest $request, PassportEntry $passport, MedicalService $medicals): PassportEntryResource
    {
        Gate::authorize('updateMedicalSlip', $passport);

        if ($request->validated('medical_slip_date') === null) {
            Gate::authorize('undo', PassportStepRecord::class);
        }

        $medicals->updateSlip($passport, $request->validated());

        return new PassportEntryResource($passport->load(PassportEntryResource::RELATIONS));
    }
}
