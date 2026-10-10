<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\ListMedicalQueueRequest;
use App\Models\PassportEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class MedicalQueueController extends Controller
{
    /**
     * Passports waiting for a medical, oldest received first (cursor
     * paginated), for the SPA's "next pending" button.
     */
    public function __invoke(ListMedicalQueueRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', PassportEntry::class);

        $page = PassportEntry::query()
            ->select(['id', 'passport_name', 'passport_number', 'passport_received_date'])
            // Pending: slip date entered, no result yet (not_started is left out).
            ->whereNull('current_medical_id')
            ->whereNotNull('medical_slip_date')
            ->orderBy('passport_received_date')
            ->orderBy('id')
            ->cursorPaginate($request->integer('per_page', 15))
            ->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (PassportEntry $entry) => [
                'id' => $entry->id,
                'passport_name' => $entry->passport_name,
                'passport_number' => $entry->passport_number,
                'passport_received_date' => $entry->passport_received_date->toDateString(),
            ])->all(),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
            ],
        ]);
    }
}
