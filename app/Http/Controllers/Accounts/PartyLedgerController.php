<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\LedgerRequest;
use App\Http\Resources\PartyResource;
use App\Models\Party;
use App\Support\PartyLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The party ledger: lines with running balance for an optional date range.
 */
class PartyLedgerController extends Controller
{
    public function __invoke(LedgerRequest $request, Party $party): JsonResponse
    {
        Gate::authorize('view', $party);

        $ledger = PartyLedger::for($party, $request->validated('from'), $request->validated('to'));

        return response()->json([
            'party' => new PartyResource(Party::query()->withTotals()->findOrFail($party->id)),
            ...$ledger,
        ]);
    }
}
