<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\ListCashPaymentsRequest;
use App\Http\Requests\Accounts\StoreCashPaymentRequest;
use App\Http\Resources\CashPaymentResource;
use App\Models\CashPayment;
use App\Services\CashPaymentService;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Cash management: money paid to parties, owners and other payees.
 */
class CashPaymentController extends Controller
{
    /** Who prepared, edited, approved or returned an entry. */
    private const PEOPLE = ['creator:id,name', 'updater:id,name', 'approver:id,name', 'returner:id,name'];

    public function __construct(private readonly CashPaymentService $payments) {}

    /**
     * Payments, newest first, with the total of every payment matching the
     * filters (all pages) in meta.totals.
     */
    public function index(ListCashPaymentsRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', CashPayment::class);

        $filters = $request->validated();
        $search = trim((string) ($filters['q'] ?? ''));

        $query = CashPayment::query()
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('date', '<=', $to))
            ->when($filters['party_id'] ?? null, fn (Builder $q, int $id) => $q->where('party_id', $id))
            ->when($filters['payee_type'] ?? null, fn (Builder $q, string $type) => $q->where('payee_type', $type))
            ->when($filters['payment_type'] ?? null, fn (Builder $q, string $type) => $q->where('payment_type', $type))
            ->when($filters['payment_method'] ?? null, fn (Builder $q, string $method) => $q->where('payment_method', $method))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($search) {
                $pattern = Like::contains($search);
                $q->where('payment_no', 'like', $pattern)
                    ->orWhere('payee_name', 'like', $pattern)
                    ->orWhere('purpose', 'like', $pattern)
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

        $payments = $query
            ->with(['party', ...self::PEOPLE])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return CashPaymentResource::collection($payments)->additional([
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

    public function show(CashPayment $cashPayment): CashPaymentResource
    {
        Gate::authorize('view', $cashPayment);

        return new CashPaymentResource($cashPayment->load(['party', ...self::PEOPLE]));
    }

    public function store(StoreCashPaymentRequest $request): CashPaymentResource
    {
        Gate::authorize('create', CashPayment::class);

        $payment = $this->payments->create($request->validated(), $request->user());

        return new CashPaymentResource($payment->load(['party', ...self::PEOPLE]));
    }

    public function update(StoreCashPaymentRequest $request, CashPayment $cashPayment): CashPaymentResource
    {
        Gate::authorize('update', $cashPayment);

        $payment = $this->payments->update($cashPayment, $request->validated(), $request->user());

        return new CashPaymentResource($payment->load(['party', ...self::PEOPLE]));
    }

    public function destroy(CashPayment $cashPayment): Response
    {
        Gate::authorize('delete', $cashPayment);

        $this->payments->delete($cashPayment);

        return response()->noContent();
    }
}
