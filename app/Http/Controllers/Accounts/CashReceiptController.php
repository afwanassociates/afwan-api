<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\ListCashReceiptsRequest;
use App\Http\Requests\Accounts\StoreCashReceiptRequest;
use App\Http\Resources\CashReceiptResource;
use App\Models\CashReceipt;
use App\Services\CashReceiptService;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Cash management: money received from parties and owners.
 */
class CashReceiptController extends Controller
{
    /** Who prepared, edited, approved or returned an entry. */
    private const PEOPLE = ['creator:id,name', 'updater:id,name', 'approver:id,name', 'returner:id,name'];

    public function __construct(private readonly CashReceiptService $receipts) {}

    /**
     * Receipts, newest first, with the total of every receipt matching the
     * filters (all pages) in meta.totals.
     */
    public function index(ListCashReceiptsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CashReceipt::class);

        $filters = $request->validated();
        $search = trim((string) ($filters['q'] ?? ''));

        $query = CashReceipt::query()
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('date', '<=', $to))
            ->when($filters['party_id'] ?? null, fn (Builder $q, int $id) => $q->where('party_id', $id))
            ->when($filters['party_type'] ?? null, fn (Builder $q, string $type) => $q->whereRelation('party', 'type', $type))
            ->when($filters['receipt_type'] ?? null, fn (Builder $q, string $type) => $q->where('receipt_type', $type))
            ->when($filters['payment_method'] ?? null, fn (Builder $q, string $method) => $q->where('payment_method', $method))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($search) {
                $pattern = Like::contains($search);
                $q->where('receipt_no', 'like', $pattern)
                    ->orWhereHas('party', fn (Builder $p) => $p->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern));
            }));

        // Totals count approved entries only (what is in the books); pending ones separately.
        $pending = (clone $query)->where('status', ApprovalStatus::Pending->value)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->toBase()
            ->first();

        $totals = (clone $query)->where('status', ApprovalStatus::Approved->value)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount END), 0) as cash")
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'bank' THEN amount END), 0) as bank")
            ->toBase()
            ->first();

        $receipts = $query
            ->with(['party', ...self::PEOPLE])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return CashReceiptResource::collection($receipts)->additional([
            'meta' => ['totals' => [
                'count' => (int) $totals->count,
                'amount' => round((float) $totals->amount, 2),
                'cash' => round((float) $totals->cash, 2),
                'bank' => round((float) $totals->bank, 2),
            ], 'pending' => [
                'count' => (int) $pending->count,
                'amount' => round((float) $pending->amount, 2),
            ]],
        ]);
    }

    public function show(CashReceipt $cashReceipt): CashReceiptResource
    {
        Gate::authorize('view', $cashReceipt);

        return new CashReceiptResource($cashReceipt->load(['party', ...self::PEOPLE]));
    }

    public function store(StoreCashReceiptRequest $request): CashReceiptResource
    {
        Gate::authorize('create', CashReceipt::class);

        $receipt = $this->receipts->create($request->validated(), $request->user());

        return new CashReceiptResource($receipt->load(['party', ...self::PEOPLE]));
    }

    public function update(StoreCashReceiptRequest $request, CashReceipt $cashReceipt): CashReceiptResource
    {
        Gate::authorize('update', $cashReceipt);

        $receipt = $this->receipts->update($cashReceipt, $request->validated(), $request->user());

        return new CashReceiptResource($receipt->load(['party', ...self::PEOPLE]));
    }

    public function destroy(CashReceipt $cashReceipt): Response
    {
        Gate::authorize('delete', $cashReceipt);

        $this->receipts->delete($cashReceipt);

        return response()->noContent();
    }
}
