<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Models\CashTransfer;
use App\Models\User;
use App\Support\YearlyNumber;

/**
 * Cash ↔ bank transfers. Every write runs through CashBook, so a deposit can
 * never take more cash than is in hand. A new or edited transfer is pending
 * until approved (App\Services\ApprovalService).
 */
class CashTransferService
{
    public function __construct(private readonly CashBook $cashBook) {}

    /**
     * Record a transfer with the next number of its year (CT-2026-0001).
     *
     * @param  array<string, mixed>  $data  validated StoreCashTransferRequest data
     */
    public function create(array $data, User $user): CashTransfer
    {
        $this->cashBook->assertOnOrAfterOpening($data['date']);

        return $this->cashBook->guarded(function () use ($data, $user) {
            $transfer = new CashTransfer($data);
            $transfer->transfer_no = YearlyNumber::assign($transfer, $data['date'], 'CT');
            $transfer->created_by = $user->id;
            $transfer->updated_by = $user->id;
            $transfer->status = ApprovalStatus::Pending;
            $transfer->save();

            return $transfer;
        }, asIfApproved: true);
    }

    /**
     * @param  array<string, mixed>  $data  validated StoreCashTransferRequest data
     */
    public function update(CashTransfer $transfer, array $data, User $user): CashTransfer
    {
        $this->cashBook->assertOnOrAfterOpening($data['date']);

        return $this->cashBook->guarded(function () use ($transfer, $data, $user) {
            $transfer->fill($data);
            $transfer->updated_by = $user->id;
            $transfer->status = ApprovalStatus::Pending;
            $transfer->save();

            return $transfer;
        }, asIfApproved: true);
    }

    /**
     * Delete a transfer that is not approved (it never counted).
     */
    public function delete(CashTransfer $transfer): void
    {
        $transfer->delete();
    }
}
