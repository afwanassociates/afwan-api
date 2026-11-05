<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\BusinessType;
use App\Enums\CashReceiptType;
use App\Enums\PartyType;
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
    public function __construct(private readonly CashBook $cashBook, private readonly OwnerLoans $ownerLoans) {}

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
            $this->checkLoanReturn($receipt);
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
            $this->checkLoanReturn($receipt);
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
     * An owner's loan return may not exceed what is outstanding on the loan it names.
     */
    private function checkLoanReturn(CashReceipt $receipt): void
    {
        if ($receipt->receipt_type === CashReceiptType::LoanReturn) {
            $this->ownerLoans->assertSettleable($receipt->owner_loan_id, $receipt->party_id, (float) $receipt->amount, $receipt->id);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkDate(array $data): void
    {
        // Only money received as cash goes into cash in hand.
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
        $isMobile = $data['payment_method'] === PaymentMethod::MobileBanking->value;
        // Business and country are for a party's money only; the country for recruitment only.
        $isClient = $data['party_type'] === PartyType::Client->value;
        $business = $isClient ? $data['business_type'] : null;

        return [
            'date' => $data['date'],
            'party_id' => $data['party_id'],
            'receipt_type' => $data['receipt_type'],
            // A loan return names the owner loan it settles.
            'owner_loan_id' => $data['receipt_type'] === CashReceiptType::LoanReturn->value ? $data['owner_loan_id'] : null,
            'business_type' => $business,
            'country_code' => $business === BusinessType::Recruitment->value ? $data['country_code'] : null,
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            // Bank details only mean something for a bank deposit.
            'bank_name' => $isBank ? $data['bank_name'] : null,
            'bank_branch' => $isBank ? $data['bank_branch'] : null,
            'bank_account_name' => $isBank ? $data['bank_account_name'] : null,
            'bank_account_number' => $isBank ? $data['bank_account_number'] : null,
            // Likewise the provider and wallet number for mobile banking.
            'mobile_provider' => $isMobile ? $data['mobile_provider'] : null,
            'mobile_number' => $isMobile ? $data['mobile_number'] : null,
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
