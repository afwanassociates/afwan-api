<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\ListCashTransfersRequest;
use App\Http\Requests\Accounts\StoreCashTransferRequest;
use App\Http\Resources\CashTransferResource;
use App\Models\CashTransfer;
use App\Services\CashTransferService;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Cash management: cash deposited to a bank or withdrawn from a bank.
 */
class CashTransferController extends Controller
{
    /** Who prepared, edited, approved or returned an entry. */
    private const PEOPLE = ['creator:id,name', 'updater:id,name', 'approver:id,name', 'returner:id,name'];

    public function __construct(private readonly CashTransferService $transfers) {}

    /**
     * Transfers, newest first, with deposit and withdrawal totals of every
     * matching transfer (all pages) in meta.totals.
     */
    public function index(ListCashTransfersRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CashTransfer::class);

        $filters = $request->validated();
        $search = trim((string) ($filters['q'] ?? ''));

        $query = CashTransfer::query()
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('date', '<=', $to))
            ->when($filters['direction'] ?? null, fn (Builder $q, string $direction) => $q->where('direction', $direction))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($search) {
                $pattern = Like::contains($search);
                $q->where('transfer_no', 'like', $pattern)
                    ->orWhere('bank_name', 'like', $pattern)
                    ->orWhere('bank_branch', 'like', $pattern);
            }));

        // Totals count approved transfers only; pending ones separately.
        $pending = (clone $query)->where('status', ApprovalStatus::Pending->value)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount')
            ->toBase()
            ->first();

        $totals = (clone $query)->where('status', ApprovalStatus::Approved->value)
            ->selectRaw('COUNT(*) as count')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'deposit' THEN amount END), 0) as deposit")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'withdrawal' THEN amount END), 0) as withdrawal")
            ->toBase()
            ->first();

        $transfers = $query
            ->with(self::PEOPLE)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return CashTransferResource::collection($transfers)->additional([
            'meta' => ['totals' => [
                'count' => (int) $totals->count,
                'deposit' => round((float) $totals->deposit, 2),
                'withdrawal' => round((float) $totals->withdrawal, 2),
            ], 'pending' => [
                'count' => (int) $pending->count,
                'amount' => round((float) $pending->amount, 2),
            ]],
        ]);
    }

    public function show(CashTransfer $cashTransfer): CashTransferResource
    {
        Gate::authorize('view', $cashTransfer);

        return new CashTransferResource($cashTransfer->load(self::PEOPLE));
    }

    public function store(StoreCashTransferRequest $request): CashTransferResource
    {
        Gate::authorize('create', CashTransfer::class);

        $transfer = $this->transfers->create($request->validated(), $request->user());

        return new CashTransferResource($transfer->load(self::PEOPLE));
    }

    public function update(StoreCashTransferRequest $request, CashTransfer $cashTransfer): CashTransferResource
    {
        Gate::authorize('update', $cashTransfer);

        $transfer = $this->transfers->update($cashTransfer, $request->validated(), $request->user());

        return new CashTransferResource($transfer->load(self::PEOPLE));
    }

    public function destroy(CashTransfer $cashTransfer): Response
    {
        Gate::authorize('delete', $cashTransfer);

        $this->transfers->delete($cashTransfer);

        return response()->noContent();
    }
}
