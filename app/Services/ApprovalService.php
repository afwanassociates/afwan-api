<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\CashTransfer;
use App\Models\PartyTransaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Approval of accounts entries (Prepared → Approved). Approving posts the
 * entry: it starts to count in the cash book, cash in hand and the party
 * ledger. Returning sends it back to its preparer with a reason; an approved
 * entry returned for correction stops counting. Both run through CashBook, so
 * cash in hand is checked again: approving a payment, or returning a receipt
 * whose cash was spent, is refused if a day would go negative.
 *
 * Who may do what is decided by the policies (approve, sendBack).
 */
class ApprovalService
{
    public function __construct(private readonly CashBook $cashBook) {}

    public function approve(CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry, User $user): CashReceipt|CashPayment|CashTransfer|PartyTransaction
    {
        return $this->cashBook->guarded(function () use ($entry, $user) {
            $entry = $this->locked($entry);

            if (! $entry->isPending()) {
                throw ValidationException::withMessages(['status' => 'This entry is no longer pending. Reload the page.']);
            }

            $entry->status = ApprovalStatus::Approved;
            $entry->approved_by = $user->id;
            $entry->approved_at = now();
            $entry->save();

            $this->syncLedgerLine($entry);

            return $entry;
        }, 'status');
    }

    public function sendBack(CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry, User $user, string $reason): CashReceipt|CashPayment|CashTransfer|PartyTransaction
    {
        return $this->cashBook->guarded(function () use ($entry, $user, $reason) {
            $entry = $this->locked($entry);

            if ($entry->status === ApprovalStatus::Rejected) {
                throw ValidationException::withMessages(['status' => 'This entry was already returned. Reload the page.']);
            }

            $entry->status = ApprovalStatus::Rejected;
            $entry->approved_by = null;
            $entry->approved_at = null;
            $entry->returned_by = $user->id;
            $entry->returned_at = now();
            $entry->return_reason = $reason;
            $entry->save();

            $this->syncLedgerLine($entry);

            return $entry;
        }, 'status');
    }

    /**
     * The entry re-read under a row lock, so two approvers never act at once.
     *
     * @template T of CashReceipt|CashPayment|CashTransfer|PartyTransaction
     *
     * @param  T  $entry
     * @return T
     */
    private function locked(CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): CashReceipt|CashPayment|CashTransfer|PartyTransaction
    {
        return $entry->newQuery()->lockForUpdate()->findOrFail($entry->getKey());
    }

    /**
     * A receipt's or payment's ledger line follows its status.
     */
    private function syncLedgerLine(CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): void
    {
        if ($entry instanceof CashReceipt || $entry instanceof CashPayment) {
            $entry->ledgerLine()->update(['status' => $entry->status->value]);
        }
    }
}
