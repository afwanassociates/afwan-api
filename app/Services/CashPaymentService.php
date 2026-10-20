<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\CashPaymentType;
use App\Enums\PayeeType;
use App\Enums\PaymentMethod;
use App\Models\CashPayment;
use App\Models\User;
use App\Support\YearlyNumber;

/**
 * Every cash payment write goes through here: the payment, the line it
 * writes in a party/owner ledger (none for other payees), and the cash book
 * check that cash in hand never goes negative. A new or edited payment is
 * pending until approved (App\Services\ApprovalService).
 */
class CashPaymentService
{
    public function __construct(private readonly CashBook $cashBook) {}

    /**
     * Record a payment with the next number of its year (CP-2026-0001).
     *
     * @param  array<string, mixed>  $data  validated StoreCashPaymentRequest data
     */
    public function create(array $data, User $user): CashPayment
    {
        $this->checkDate($data);

        return $this->cashBook->guarded(function () use ($data, $user) {
            $payment = new CashPayment($this->attributes($data));
            $payment->payment_no = YearlyNumber::assign($payment, $data['date'], 'CP');
            $payment->created_by = $user->id;
            $payment->updated_by = $user->id;
            $payment->status = ApprovalStatus::Pending;
            $payment->save();

            $this->syncLedgerLine($payment, $user);

            return $payment;
        }, asIfApproved: true);
    }

    /**
     * Edit a pending or returned payment; it becomes pending again. Its number never changes.
     *
     * @param  array<string, mixed>  $data  validated StoreCashPaymentRequest data
     */
    public function update(CashPayment $payment, array $data, User $user): CashPayment
    {
        $this->checkDate($data);

        return $this->cashBook->guarded(function () use ($payment, $data, $user) {
            $payment->fill($this->attributes($data));
            $payment->updated_by = $user->id;
            $payment->status = ApprovalStatus::Pending;
            $payment->save();

            $this->syncLedgerLine($payment, $user);

            return $payment;
        }, asIfApproved: true);
    }

    /**
     * Delete a payment that is not approved (it never counted); its ledger
     * line goes with it (cascade).
     */
    public function delete(CashPayment $payment): void
    {
        $payment->delete();
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
        $isOther = $data['payee_type'] === PayeeType::Other->value;

        return [
            'date' => $data['date'],
            'payee_type' => $data['payee_type'],
            // A party/owner payee is the party record; another payee is typed.
            'party_id' => $isOther ? null : $data['party_id'],
            'payee_name' => $isOther ? $data['payee_name'] : null,
            'payment_type' => $data['payment_type'],
            'purpose' => $data['purpose'] ?? null,
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            'bank_name' => $isBank ? $data['bank_name'] : null,
            'bank_branch' => $isBank ? $data['bank_branch'] : null,
            'note' => $data['note'] ?? null,
        ];
    }

    /**
     * Write, rewrite or remove the payment's party-ledger line.
     */
    private function syncLedgerLine(CashPayment $payment, User $user): void
    {
        /** @var CashPaymentType $type */
        $type = $payment->payment_type;
        $ledgerType = $type->ledgerType();

        if ($payment->party_id === null || $ledgerType === null) {
            $payment->ledgerLine()->delete();

            return;
        }

        $line = $payment->ledgerLine()->firstOrNew();
        $line->party_id = $payment->party_id;
        $line->status = $payment->status;
        $line->fill([
            'date' => $payment->date,
            'type' => $ledgerType,
            'amount' => $payment->amount,
            'note' => $payment->purpose ?? $payment->note,
        ]);
        $line->created_by ??= $user->id;
        $line->save();
    }
}
