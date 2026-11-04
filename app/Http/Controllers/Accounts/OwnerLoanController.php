<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\CashPaymentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\StoreOwnerLoanBillRequest;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\OwnerLoanBill;
use App\Services\OwnerLoans;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Owner loans page: each loan the company gave an owner (a cash payment
 * of type owner_loan) with what is returned, settled by bills and still
 * outstanding, and the owners' capital. The cash receive form uses the list
 * too, to pick the loan a return settles (party_id, status=open, for_receipt
 * = the receipt being edited). Bills the owner paid with a loan are recorded
 * here, each with a copy of the bill.
 */
class OwnerLoanController extends Controller
{
    public function __construct(private readonly OwnerLoans $ownerLoans) {}

    /**
     * GET /owner-loans?party_id&status=open|settled&for_receipt
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CashReceipt::class);

        $filters = $request->validate([
            'party_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', Rule::in(['open', 'settled'])],
            'for_receipt' => ['sometimes', 'nullable', 'integer'],
        ]);

        return response()->json($this->ownerLoans->report([
            'party_id' => isset($filters['party_id']) ? (int) $filters['party_id'] : null,
            'status' => $filters['status'] ?? null,
            'for_receipt' => isset($filters['for_receipt']) ? (int) $filters['for_receipt'] : null,
        ], $request->user()));
    }

    /**
     * POST /owner-loans/{loan}/bills (multipart, with bill_copy)
     */
    public function storeBill(StoreOwnerLoanBillRequest $request, CashPayment $loan): JsonResponse
    {
        abort_unless($loan->payment_type === CashPaymentType::OwnerLoan, 404);
        Gate::authorize('create', OwnerLoanBill::class);

        $bill = $this->ownerLoans->createBill($loan, $request->safe()->except('bill_copy'), $request->file('bill_copy'), $request->user());

        return response()->json(['data' => $this->ownerLoans->billRow($bill->fresh(), $request->user())], 201);
    }

    /**
     * POST /owner-loan-bills/{bill} (multipart; bill_copy optional)
     */
    public function updateBill(StoreOwnerLoanBillRequest $request, OwnerLoanBill $bill): JsonResponse
    {
        Gate::authorize('update', $bill);

        $bill = $this->ownerLoans->updateBill($bill, $request->safe()->except('bill_copy'), $request->file('bill_copy'), $request->user());

        return response()->json(['data' => $this->ownerLoans->billRow($bill->fresh(), $request->user())]);
    }

    public function destroyBill(OwnerLoanBill $bill): Response
    {
        Gate::authorize('delete', $bill);

        $this->ownerLoans->deleteBill($bill);

        return response()->noContent();
    }

    /**
     * GET /owner-loan-bills/{bill}/copy — the bill copy, inline (or ?download=1). Never cached.
     */
    public function billCopy(Request $request, OwnerLoanBill $bill): StreamedResponse|JsonResponse
    {
        Gate::authorize('view', $bill);

        if (! $this->ownerLoans->copyExists($bill)) {
            return response()->json(['message' => 'This bill has no copy.'], 404);
        }

        $headers = [
            'Content-Type' => $bill->copy_mime,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $name = $bill->copy_original_name ?: basename($bill->copy_path);

        return $request->boolean('download')
            ? $this->ownerLoans->disk()->download($bill->copy_path, $name, $headers)
            : $this->ownerLoans->disk()->response($bill->copy_path, $name, $headers);
    }
}
