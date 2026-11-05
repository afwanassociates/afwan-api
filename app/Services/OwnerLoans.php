<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\CashPaymentType;
use App\Enums\CashReceiptType;
use App\Enums\PartyTransactionType;
use App\Enums\PartyType;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\OwnerLoanBill;
use App\Models\Party;
use App\Models\User;
use App\Rules\PassportScanFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Owner loans: the company lends an owner money (an approved cash payment of
 * type owner_loan, by cash, bank or mobile banking, so the company's balance
 * goes down). The owner settles it in two ways, both naming the loan:
 *
 * - returning money (a cash receipt of type loan_return): the company's
 *   balance goes back up;
 * - showing company bills they paid with it (an owner loan bill, with a copy
 *   of the bill): no money moves.
 *
 * A loan is open while something is still outstanding and settled once its
 * approved returns and bills cover it. Only approved settlements count; a new
 * one may not exceed what is outstanding less the others waiting for approval,
 * and is checked again (against approved ones) when it is approved.
 *
 * Money an owner puts into the company is capital (a cash receipt), never a loan.
 */
class OwnerLoans
{
    /** The private disk (storage/app/private), never served by the web server. */
    public const DISK = 'local';

    /** Folder of the bill copies on that disk. */
    public const FOLDER = 'owner-loan-bills';

    /**
     * Refuse a return or bill larger than what is outstanding on the loan.
     * Call inside a transaction: the loan row is locked.
     */
    public function assertSettleable(
        ?int $loanId,
        ?int $ownerId,
        float $amount,
        ?int $exceptReceiptId = null,
        ?int $exceptBillId = null,
        bool $atApproval = false,
    ): void {
        $loan = $loanId ? CashPayment::query()->lockForUpdate()->find($loanId) : null;

        if (! $loan || $loan->payment_type !== CashPaymentType::OwnerLoan || ($ownerId !== null && $loan->party_id !== $ownerId)) {
            throw ValidationException::withMessages(['owner_loan_id' => 'Choose a loan given to this owner.']);
        }
        if (! $loan->isApproved()) {
            throw ValidationException::withMessages(['owner_loan_id' => "Loan {$loan->payment_no} is not approved yet, so it cannot be settled."]);
        }

        $outstanding = round((float) $loan->amount - $this->settled($loan, ! $atApproval, $exceptReceiptId, $exceptBillId), 2);

        if ($amount > $outstanding + 0.001) {
            $waiting = $atApproval ? '.' : ' (counting returns and bills waiting for approval).';
            throw ValidationException::withMessages([
                'amount' => $outstanding <= 0
                    ? "Loan {$loan->payment_no} has nothing left to settle{$waiting}"
                    : 'Only ৳'.number_format($outstanding, 2)." is outstanding on loan {$loan->payment_no}{$waiting}",
            ]);
        }
    }

    /**
     * Refuse returning (for correction) an approved loan that has returns or bills.
     */
    public function assertNoSettlements(CashPayment $loan): void
    {
        if ($loan->payment_type !== CashPaymentType::OwnerLoan) {
            return;
        }
        $counting = [ApprovalStatus::Approved->value, ApprovalStatus::Pending->value];
        $has = $loan->loanReturns()->whereIn('status', $counting)->exists()
            || $loan->loanBills()->whereIn('status', $counting)->exists();

        if ($has) {
            throw ValidationException::withMessages([
                'status' => "Loan {$loan->payment_no} already has returns or bills. Return or delete those first.",
            ]);
        }
    }

    /* ---------- Bills ---------- */

    /**
     * Record a bill the owner paid with the loan; it waits for approval.
     *
     * @param  array<string, mixed>  $data  validated StoreOwnerLoanBillRequest data
     */
    public function createBill(CashPayment $loan, array $data, UploadedFile $copy, User $user): OwnerLoanBill
    {
        return $this->withCopy($copy, function (?array $file) use ($loan, $data, $user) {
            $this->assertSettleable($loan->id, null, (float) $data['amount']);

            $bill = new OwnerLoanBill($this->billAttributes($data));
            $bill->owner_loan_id = $loan->id;
            $bill->created_by = $user->id;
            $bill->updated_by = $user->id;
            $bill->status = ApprovalStatus::Pending;
            $bill->forceFill($file ?? [])->save();
            $this->syncLedgerLine($bill, $loan, $user);

            return $bill;
        });
    }

    /**
     * Edit a waiting or returned bill (optionally with a new copy); it waits for approval again.
     *
     * @param  array<string, mixed>  $data  validated StoreOwnerLoanBillRequest data
     */
    public function updateBill(OwnerLoanBill $bill, array $data, ?UploadedFile $copy, User $user): OwnerLoanBill
    {
        $oldPath = $bill->copy_path;

        $bill = $this->withCopy($copy, function (?array $file) use ($bill, $data, $user) {
            $this->assertSettleable($bill->owner_loan_id, null, (float) $data['amount'], exceptBillId: $bill->id);

            $bill->fill($this->billAttributes($data));
            $bill->updated_by = $user->id;
            $bill->status = ApprovalStatus::Pending;
            $bill->forceFill($file ?? [])->save();
            $this->syncLedgerLine($bill, $bill->ownerLoan, $user);

            return $bill;
        });

        if ($copy !== null && $oldPath !== null && $oldPath !== $bill->copy_path) {
            $this->disk()->delete($oldPath);
        }

        return $bill;
    }

    /**
     * Delete a bill that is not approved, with its ledger line and copy.
     */
    public function deleteBill(OwnerLoanBill $bill): void
    {
        $path = $bill->copy_path;
        $bill->delete();
        if ($path !== null) {
            $this->disk()->delete($path);
        }
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    /**
     * Whether the bill's copy is on the disk.
     */
    public function copyExists(OwnerLoanBill $bill): bool
    {
        return $bill->copy_path !== null && $this->disk()->exists($bill->copy_path);
    }

    /* ---------- Report ---------- */

    /**
     * The owners' capital and the loans given to them for the Owner loans page.
     * `for_receipt` leaves that loan return out (it is being edited).
     *
     * @param  array{party_id?: int|null, status?: string|null, for_receipt?: int|null}  $filters
     * @return array<string, mixed>
     */
    public function report(array $filters, ?User $user = null): array
    {
        $counting = [ApprovalStatus::Approved->value, ApprovalStatus::Pending->value];

        $loans = CashPayment::query()
            ->approved()
            ->where('payment_type', CashPaymentType::OwnerLoan->value)
            ->with([
                'party:id,code,name,mobile,address',
                'loanReturns' => fn ($q) => $q->whereIn('status', $counting)->orderBy('date')->orderBy('id'),
                'loanBills' => fn ($q) => $q->with('returner:id,name')->orderBy('date')->orderBy('id'),
            ])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (CashPayment $loan) => $this->loanRow($loan, $filters['for_receipt'] ?? null, $user));

        $owners = $this->owners($loans);

        return [
            'summary' => [
                'total_capital' => round($owners->sum('capital'), 2),
                'loans_outstanding' => round($loans->sum('outstanding'), 2),
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
     * One loan with what is returned, settled by bills and still outstanding.
     *
     * @return array<string, mixed>
     */
    private function loanRow(CashPayment $loan, ?int $forReceipt, ?User $user): array
    {
        $approved = ApprovalStatus::Approved;
        $pending = ApprovalStatus::Pending;
        $returns = $loan->loanReturns->reject(fn (CashReceipt $r) => $r->id === $forReceipt);
        $amount = round((float) $loan->amount, 2);
        $returned = round((float) $returns->where('status', $approved)->sum('amount'), 2);
        $billed = round((float) $loan->loanBills->where('status', $approved)->sum('amount'), 2);
        $outstanding = round($amount - $returned - $billed, 2);

        return [
            'id' => $loan->id,
            'payment_no' => $loan->payment_no,
            'date' => $loan->date->format('Y-m-d'),
            'owner' => [
                'id' => $loan->party->id, 'code' => $loan->party->code, 'name' => $loan->party->name,
                'mobile' => $loan->party->mobile, 'address' => $loan->party->address,
            ],
            'payment_method' => $loan->payment_method->value,
            'payment_method_label' => $loan->payment_method->label(),
            'amount' => $amount,
            'returned' => $returned,
            'billed' => $billed,
            'outstanding' => $outstanding,
            // Waiting for approval: not settled yet.
            'pending_returned' => round((float) $returns->where('status', $pending)->sum('amount'), 2),
            'pending_billed' => round((float) $loan->loanBills->where('status', $pending)->sum('amount'), 2),
            'status' => $outstanding > 0.001 ? 'open' : 'settled',
            'note' => $loan->purpose ?? $loan->note,
            'returns' => $returns->map(fn (CashReceipt $r) => [
                'id' => $r->id,
                'receipt_no' => $r->receipt_no,
                'date' => $r->date->format('Y-m-d'),
                'amount' => round((float) $r->amount, 2),
                'payment_method_label' => $r->payment_method->label(),
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
            ])->values()->all(),
            'bills' => $loan->loanBills->map(fn (OwnerLoanBill $b) => $this->billRow($b, $user))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function billRow(OwnerLoanBill $bill, ?User $user = null): array
    {
        return [
            'id' => $bill->id,
            'owner_loan_id' => $bill->owner_loan_id,
            'date' => $bill->date->format('Y-m-d'),
            'expense_category' => $bill->expense_category->value,
            'expense_category_label' => $bill->expense_category->label(),
            'purpose' => $bill->purpose,
            'bill_no' => $bill->bill_no,
            'amount' => round((float) $bill->amount, 2),
            'note' => $bill->note,
            'has_copy' => $bill->copy_path !== null,
            'copy_name' => $bill->copy_original_name,
            'status' => $bill->status->value,
            'status_label' => $bill->status->label(),
            'returned_by' => $bill->relationLoaded('returner') ? $bill->returner?->name : null,
            'return_reason' => $bill->return_reason,
            'can' => [
                'update' => $user?->can('update', $bill) ?? false,
                'delete' => $user?->can('delete', $bill) ?? false,
            ],
        ];
    }

    /**
     * Each owner's capital (what they paid in) and what they still owe on loans.
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
                'capital' => round((float) $owner->getAttribute(PartyTransactionType::Capital->value.'_total'), 2),
                'loan_outstanding' => round($loans->where('owner.id', $owner->id)->sum('outstanding'), 2),
            ]);
    }

    /**
     * Returns and bills that count against the loan: approved, plus pending ones unless at approval.
     */
    private function settled(CashPayment $loan, bool $includePending, ?int $exceptReceiptId, ?int $exceptBillId): float
    {
        $statuses = $includePending
            ? [ApprovalStatus::Approved->value, ApprovalStatus::Pending->value]
            : [ApprovalStatus::Approved->value];

        $returns = CashReceipt::query()
            ->where('owner_loan_id', $loan->id)
            ->where('receipt_type', CashReceiptType::LoanReturn->value)
            ->whereIn('status', $statuses)
            ->when($exceptReceiptId, fn ($q, int $id) => $q->whereKeyNot($id))
            ->sum('amount');
        $bills = OwnerLoanBill::query()
            ->where('owner_loan_id', $loan->id)
            ->whereIn('status', $statuses)
            ->when($exceptBillId, fn ($q, int $id) => $q->whereKeyNot($id))
            ->sum('amount');

        return (float) $returns + (float) $bills;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function billAttributes(array $data): array
    {
        return [
            'date' => $data['date'],
            'expense_category' => $data['expense_category'],
            'purpose' => $data['purpose'],
            'bill_no' => $data['bill_no'] ?? null,
            'amount' => $data['amount'],
            'note' => $data['note'] ?? null,
        ];
    }

    /**
     * Save the copy first, then run the write in a transaction with the copy's
     * columns; if the write fails, the new copy is removed again.
     *
     * @template T
     *
     * @param  callable(array<string, mixed>|null): T  $write
     * @return T
     */
    private function withCopy(?UploadedFile $copy, callable $write): mixed
    {
        $file = null;
        if ($copy !== null) {
            $mime = PassportScanFile::realMime($copy);
            $extension = PassportScanFile::TYPES[$mime][0] ?? throw new RuntimeException('Unsupported bill copy type.');
            // A random name: the client's file name is kept only in copy_original_name.
            $path = self::FOLDER.'/'.Str::uuid().".{$extension}";
            if ($this->disk()->putFileAs(dirname($path), $copy, basename($path)) === false) {
                throw new RuntimeException('The bill copy could not be saved.');
            }
            $file = [
                'copy_path' => $path,
                'copy_original_name' => Str::limit($copy->getClientOriginalName(), 250, ''),
                'copy_mime' => $mime,
                'copy_size' => $copy->getSize(),
            ];
        }

        try {
            return DB::transaction(fn () => $write($file));
        } catch (\Throwable $e) {
            if ($file !== null) {
                $this->disk()->delete($file['copy_path']);
            }
            throw $e;
        }
    }

    /**
     * Write (or rewrite) the bill's line in the owner's ledger: a credit that
     * settles the loan, counting once the bill is approved.
     */
    private function syncLedgerLine(OwnerLoanBill $bill, CashPayment $loan, User $user): void
    {
        $line = $bill->ledgerLine()->firstOrNew();
        $line->party_id = $loan->party_id;
        $line->status = $bill->status;
        $line->fill([
            'date' => $bill->date,
            'type' => PartyTransactionType::LoanBill,
            'amount' => $bill->amount,
            'note' => trim($bill->purpose.($bill->bill_no ? ' · Bill no. '.$bill->bill_no : '')),
        ]);
        $line->created_by ??= $user->id;
        $line->save();
    }
}
