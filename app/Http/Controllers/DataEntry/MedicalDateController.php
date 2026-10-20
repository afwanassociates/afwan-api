<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\UpdateMedicalDateRequest;
use App\Http\Resources\PassportEntryResource;
use App\Models\PassportEntry;
use App\Services\MedicalService;
use Illuminate\Support\Facades\Gate;

class MedicalDateController extends Controller
{
    /**
     * Set the passport's medical date (needs the slip date; locked once a
     * result is recorded, except for admins starting a re-test).
     */
    public function __invoke(UpdateMedicalDateRequest $request, PassportEntry $passport, MedicalService $medicals): PassportEntryResource
    {
        Gate::authorize('updateMedicalDate', $passport);

        $medicals->updateMedicalDate($passport, $request->validated('medical_date'), $request->user());

        return new PassportEntryResource($passport->load(PassportEntryResource::RELATIONS));
    }
}
