<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\CashReceiptType;
use App\Enums\PartyTransactionType;
use App\Enums\PartyType;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\Party;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Owner loans: every approved cash receipt of type loan from an owner is one
 * loan, repaid by owner loan-repayment payments that name it. A loan is open
 * while something is still owed and settled once it is fully repaid.
 *
 * Only approved repayments count as repaid. A new repayment may not exceed
 * what is still owed less the other repayments waiting for approval, and is
 * checked again (against approved ones) when it is approved.
 */
class OwnerLoans
{
    /**
     * Refuse a repayment that is larger than what is still owed on the loan.
     * Call inside a transaction: the loan row is locked.
     */
    public function assertRepayable(?int $loanId, int $ownerId, float $amount, ?int $exceptPaymentId, bool $atApproval = false): void
    {
        $loan = $loanId ? CashReceipt::query()->lockForUpdate()->find($loanId) : null;

        if (! $loan || $loan->party_id !== $ownerId || $loan->receipt_type !== CashReceiptType::Loan) {
            throw ValidationException::withMessages(['loan_receipt_id' => 'Choose a loan of this owner.']);
        }
        if (! $loan->isApproved()) {
            throw ValidationException::withMessages(['loan_receipt_id' => "Loan {$loan->receipt_no} is not approved, so it cannot be repaid yet."]);
        }

        $owed = round((float) $loan->amount - $this->repaid($loan, includePending: ! $atApproval, exceptPaymentId: $exceptPaymentId), 2);
        if ($amount > $owed + 0.001) {
            throw ValidationException::withMessages([
                'amount' => $owed <= 0
                    ? "Loan {$loan->receipt_no} has nothing left to repay".($atApproval ? '.' : ' (counting repayments waiting for approval).')
                    : 'Only ৳'.number_format($owed, 2)." is still owed on loan {$loan->receipt_no}".($atApproval ? '.' : ' (counting repayments waiting for approval).'),
            ]);
        }
    }

    /**
     * Refuse returning an approved loan that has repayments (pending or approved).
     */
    public function assertNoRepayments(CashReceipt $loan): void
    {
        if ($this->repayments($loan)->exists()) {
            throw ValidationException::withMessages([
                'status' => "Loan {$loan->receipt_no} already has repayments. Return those payments first.",
            ]);
        }
    }

    /**
     * The owners' capital and loans for the Owner loans page.
     *
     * @param  array{party_id?: int|null, status?: string|null, for_payment?: int|null}  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters): array
    {
        $loans = CashReceipt::query()
            ->approved()
            ->where('receipt_type', CashReceiptType::Loan->value)
            ->whereRelation('party', 'type', PartyType::Owner->value)
            ->with('party:id,code,name')
            ->withSum(['loanRepayments as repaid' => fn ($q) => $q->where('status', ApprovalStatus::Approved->value)], 'amount')
            ->withSum(['loanRepayments as pending_repaid' => fn ($q) => $q->where('status', ApprovalStatus::Pending->value)
                ->when($filters['for_payment'] ?? null, fn ($q, int $id) => $q->whereKeyNot($id))], 'amount')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(function (CashReceipt $loan) {
                $amount = round((float) $loan->amount, 2);
                $repaid = round((float) $loan->getAttribute('repaid'), 2);

                return [
                    'id' => $loan->id,
                    'receipt_no' => $loan->receipt_no,
                    'date' => $loan->date->format('Y-m-d'),
                    'owner' => ['id' => $loan->party->id, 'code' => $loan->party->code, 'name' => $loan->party->name],
                    'amount' => $amount,
                    'repaid' => $repaid,
                    'still_owed' => round($amount - $repaid, 2),
                    'pending_repaid' => round((float) $loan->getAttribute('pending_repaid'), 2),
                    'status' => $amount - $repaid > 0.001 ? 'open' : 'settled',
                    'note' => $loan->note,
                ];
            });

        $owners = $this->owners($loans);

        return [
            'summary' => [
                'total_capital' => round($owners->sum('capital'), 2),
                'loans_owed' => round($loans->sum('still_owed'), 2),
                'open_count' => $loans->where('status', 'open')->count(),
                'settled_count' => $loans->where('status', 'settled')->count(),
                'owners' => $owners->values()->all(),
            ],
            'data' => $loans
                ->when($filters['party_id'] ?? null, fn (Collection $c, int $id) => $c->where('owner.id', $id))
                ->when($filters['status'] ?? null, fn (Collection $c, string $status) => $c->where('status', $status))
                ->values()
                ->all(),
        ];
    }

    /**
     * Each owner's capital (paid in, less payments to the owner) and the loan still owed to them.
     *
     * @param  Collection<int, array<string, mixed>>  $loans
     * @return Collection<int, array<string, mixed>>
     */
    private function owners(Collection $loans): Collection
    {
        return Party::query()
            ->where('type', PartyType::Owner->value)
            ->withTotals()
            ->orderBy('code')
            ->get()
            ->map(fn (Party $owner) => [
                'id' => $owner->id,
                'code' => $owner->code,
                'name' => $owner->name,
                'capital' => round((float) $owner->getAttribute(PartyTransactionType::Capital->value.'_total')
                    - (float) $owner->getAttribute(PartyTransactionType::Drawing->value.'_total'), 2),
                'loan_owed' => round($loans->where('owner.id', $owner->id)->sum('still_owed'), 2),
            ]);
    }

    /**
     * Repayments of the loan that count: approved, plus pending ones unless at approval.
     */
    private function repaid(CashReceipt $loan, bool $includePending, ?int $exceptPaymentId): float
    {
        return (float) $this->repayments($loan)
            ->when(! $includePending, fn ($q) => $q->where('status', ApprovalStatus::Approved->value))
            ->when($exceptPaymentId, fn ($q, int $id) => $q->whereKeyNot($id))
            ->sum('amount');
    }

    /**
     * @return Builder<CashPayment>
     */
    private function repayments(CashReceipt $loan)
    {
        return CashPayment::query()
            ->where('loan_receipt_id', $loan->id)
            ->whereIn('status', [ApprovalStatus::Approved->value, ApprovalStatus::Pending->value]);
    }
}
