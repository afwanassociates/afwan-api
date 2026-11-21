<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransferDirection;
use App\Models\CashTransfer;
use App\Models\User;
use App\Support\YearlyNumber;

/**
 * Cash ↔ bank and cash ↔ mobile banking transfers. Every write runs through CashBook, so a deposit can
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
            $transfer = new CashTransfer(self::attributes($data));
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
            $transfer->fill(self::attributes($data));
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

    /**
     * A bank transfer has the bank details; a mobile banking one the service
     * and wallet number. The deposit slip belongs to a bank deposit only; the cheque number to a bank withdrawal only. All keep
     * who deposited or withdrew it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        if ($data['channel'] === PaymentMethod::MobileBanking->value) {
            return [...$data, ...array_fill_keys(['bank_name', 'bank_branch', 'cheque_no', ...self::DEPOSIT_FIELDS], null)];
        }

        $data = [...$data, 'mobile_provider' => null, 'mobile_number' => null];

        if ($data['direction'] === TransferDirection::Deposit->value) {
            return [...$data, 'cheque_no' => null];
        }

        return [...$data, ...array_fill_keys(self::DEPOSIT_FIELDS, null)];
    }

    private const DEPOSIT_FIELDS = ['deposit_slip_no'];
}
