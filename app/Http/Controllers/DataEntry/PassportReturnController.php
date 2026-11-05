<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ReturnPassportRequest;
use App\Http\Resources\PassportEntryResource;
use App\Models\PassportEntry;
use App\Services\PassportReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PassportReturnController extends Controller
{
    /**
     * Return the passport to its owner, at any step (the operations head). It
     * leaves every working list and takes no step until the return is undone.
     */
    public function store(ReturnPassportRequest $request, PassportEntry $passport, PassportReturnService $returns): PassportEntryResource
    {
        Gate::authorize('returnPassport', $passport);

        $returns->returnPassport($passport, $request->validated(), $request->user());

        return new PassportEntryResource($passport->load(PassportEntryResource::RELATIONS));
    }

    /**
     * Undo the return: back to exactly the step it was at; the return stays
     * in the history as undone.
     */
    public function destroy(Request $request, PassportEntry $passport, PassportReturnService $returns): PassportEntryResource
    {
        Gate::authorize('undoReturn', $passport);

        $returns->undo($passport, $request->user());

        return new PassportEntryResource($passport->load(PassportEntryResource::RELATIONS));
    }
}
