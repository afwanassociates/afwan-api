<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Models\PassportEntry;
use App\Support\PassportHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PassportHistoryController extends Controller
{
    /**
     * The passport's history from entry to flight, oldest first.
     */
    public function __invoke(PassportEntry $passport, PassportHistory $history): JsonResponse
    {
        Gate::authorize('view', $passport);

        return response()->json(['data' => $history->events($passport)]);
    }
}
