<?php

namespace App\Policies;

use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\CashTransfer;
use App\Models\PartyTransaction;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Hand-entered ledger lines (receivables) follow the approval rules of
 * AccountsEntryPolicy. Lines written by a cash receipt or payment change, and
 * are approved, only through that voucher.
 */
class PartyTransactionPolicy extends AccountsEntryPolicy
{
    public function update(User $user, CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): Response
    {
        return $this->manualOnly($entry) ?? parent::update($user, $entry);
    }

    public function delete(User $user, CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): Response
    {
        return $this->manualOnly($entry) ?? parent::delete($user, $entry);
    }

    public function approve(User $user, CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): Response
    {
        return $this->manualOnly($entry) ?? parent::approve($user, $entry);
    }

    public function sendBack(User $user, CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): Response
    {
        return $this->manualOnly($entry) ?? parent::sendBack($user, $entry);
    }

    private function manualOnly(CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): ?Response
    {
        return $entry instanceof PartyTransaction && ! $entry->isManual()
            ? Response::deny('This line belongs to a cash receipt or payment; change that instead.')
            : null;
    }
}
