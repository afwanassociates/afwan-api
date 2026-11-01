<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Models\CashReceipt;
use App\Services\OwnerLoans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The Owner loans page: the owners' capital, each loan from an owner with
 * what is repaid and still owed, open or settled. The cash payment form uses
 * it too (party_id, status=open, for_payment = the payment being edited).
 */
class OwnerLoanController extends Controller
{
    /**
     * GET /owner-loans?party_id&status=open|settled&for_payment
     */
    public function __invoke(Request $request, OwnerLoans $ownerLoans): JsonResponse
    {
        Gate::authorize('viewAny', CashReceipt::class);

        $filters = $request->validate([
            'party_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', Rule::in(['open', 'settled'])],
            'for_payment' => ['sometimes', 'nullable', 'integer'],
        ]);

        return response()->json($ownerLoans->report([
            'party_id' => isset($filters['party_id']) ? (int) $filters['party_id'] : null,
            'status' => $filters['status'] ?? null,
            'for_payment' => isset($filters['for_payment']) ? (int) $filters['for_payment'] : null,
        ]));
    }
}
