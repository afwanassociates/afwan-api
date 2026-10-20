<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\CashReceiptType;
use App\Enums\PaymentMethod;
use App\Models\CashReceipt;
use App\Models\Party;
use App\Models\User;
use App\Support\YearlyNumber;

/**
 * Every cash receipt write goes through here, so the receipt and the line it
 * writes in the party ledger always agree. A new or edited receipt is pending
 * until approved (App\Services\ApprovalService); only approved ones count.
 */
class CashReceiptService
{
    public function __construct(private readonly CashBook $cashBook) {}

    /**
     * Record a receipt with the next number of its year (CR-2026-0001).
     *
     * @param  array<string, mixed>  $data  validated StoreCashReceiptRequest data
     */
    public function create(array $data, User $user): CashReceipt
    {
        $this->checkDate($data);

        return $this->cashBook->guarded(function () use ($data, $user) {
            $receipt = new CashReceipt($this->attributes($data));
            $receipt->receipt_no = YearlyNumber::assign($receipt, $data['date'], 'CR');
            $receipt->created_by = $user->id;
            $receipt->updated_by = $user->id;
            $receipt->status = ApprovalStatus::Pending;
            $receipt->save();

            $this->syncLedgerLine($receipt, $user);

            return $receipt;
        }, asIfApproved: true);
    }

    /**
     * Edit a pending or returned receipt; it becomes pending again. Its number
     * never changes, even if the date moves to another year.
     *
     * @param  array<string, mixed>  $data  validated StoreCashReceiptRequest data
     */
    public function update(CashReceipt $receipt, array $data, User $user): CashReceipt
    {
        $this->checkDate($data);

        return $this->cashBook->guarded(function () use ($receipt, $data, $user) {
            $receipt->fill($this->attributes($data));
            $receipt->updated_by = $user->id;
            $receipt->status = ApprovalStatus::Pending;
            $receipt->save();

            $this->syncLedgerLine($receipt, $user);

            return $receipt;
        }, asIfApproved: true);
    }

    /**
     * Delete a receipt that is not approved (it never counted); its ledger
     * line goes with it (cascade).
     */
    public function delete(CashReceipt $receipt): void
    {
        $receipt->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkDate(array $data): void
    {
        if ($data['payment_method'] === PaymentMethod::Cash->value) {
            $this->cashBook->assertOnOrAfterOpening($data['date']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $isBank = $data['payment_method'] === PaymentMethod::Bank->value;

        return [
            'date' => $data['date'],
            'party_id' => $data['party_id'],
            'receipt_type' => $data['receipt_type'],
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            // Bank details only mean something for a bank deposit.
            'bank_name' => $isBank ? $data['bank_name'] : null,
            'bank_branch' => $isBank ? $data['bank_branch'] : null,
            'note' => $data['note'] ?? null,
        ];
    }

    /**
     * Write (or rewrite) the receipt's party-ledger line.
     */
    private function syncLedgerLine(CashReceipt $receipt, User $user): void
    {
        /** @var Party $party */
        $party = Party::query()->findOrFail($receipt->party_id);
        /** @var CashReceiptType $type */
        $type = $receipt->receipt_type;

        $line = $receipt->ledgerLine()->firstOrNew();
        $line->party_id = $party->id;
        $line->status = $receipt->status;
        $line->fill([
            'date' => $receipt->date,
            'type' => $type->ledgerType($party->type),
            'amount' => $receipt->amount,
            'note' => $receipt->note,
        ]);
        $line->created_by ??= $user->id;
        $line->save();
    }
}
