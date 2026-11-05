<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\CashTransfer;
use App\Models\OwnerLoanBill;
use App\Models\PartyTransaction;
use App\Models\User;

/**
 * Bills an owner paid with a company loan follow the approval rules of the
 * other accounts entries: the accounts team records and fixes them, an
 * approver approves them. Approvers (admins too) may open the bill copy.
 */
class OwnerLoanBillPolicy extends AccountsEntryPolicy
{
    public function view(User $user, CashReceipt|CashPayment|CashTransfer|PartyTransaction|OwnerLoanBill $entry): bool
    {
        return $user->hasRole(...UserRole::accountsTeam(), ...UserRole::approvers());
    }
}
