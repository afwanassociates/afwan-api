<?php

namespace App\Policies;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\CashTransfer;
use App\Models\PartyTransaction;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Shared rules of the accounts entries that need approval (cash receipts,
 * payments, transfers, hand-entered ledger lines):
 *
 * - the accounts team (accounts, accounts head) prepares them; admins only
 *   approve and return them (from the Approvals page);
 * - a pending or returned entry may be edited or deleted by whoever prepared
 *   it, or by an approver; an approved entry is locked for everyone;
 * - an approver (accounts head, admin, super admin) approves a pending entry,
 *   never one they prepared or last edited;
 * - an approver returns a pending entry with a reason; only an admin or super
 *   admin returns an approved one for correction.
 */
abstract class AccountsEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(...UserRole::accountsTeam());
    }

    public function view(User $user, CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): Response
    {
        return $this->change($user, $entry, 'edit');
    }

    public function delete(User $user, CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): Response
    {
        return $this->change($user, $entry, 'delete');
    }

    public function approve(User $user, CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): Response
    {
        if (! $user->hasRole(...UserRole::approvers())) {
            return Response::deny('Only the accounts & finance head or an admin can approve entries.');
        }

        if (! $entry->isPending()) {
            return Response::deny('Only pending entries can be approved.');
        }

        if ($entry->isPreparedBy($user)) {
            return Response::deny('You prepared or edited this entry, so someone else must approve it.');
        }

        return Response::allow();
    }

    public function sendBack(User $user, CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry): Response
    {
        return match ($entry->status) {
            ApprovalStatus::Pending => $user->hasRole(...UserRole::approvers())
                ? Response::allow()
                : Response::deny('Only the accounts & finance head or an admin can return entries.'),
            ApprovalStatus::Approved => $user->hasRole(UserRole::Admin, UserRole::SuperAdmin)
                ? Response::allow()
                : Response::deny('Only an admin can return an approved entry for correction.'),
            ApprovalStatus::Rejected => Response::deny('This entry was already returned.'),
        };
    }

    protected function change(User $user, CashReceipt|CashPayment|CashTransfer|PartyTransaction $entry, string $verb): Response
    {
        if (! $this->viewAny($user)) {
            return Response::deny();
        }

        if ($entry->isApproved()) {
            return Response::deny("Approved entries are locked. Ask an admin to return it for correction before you {$verb} it.");
        }

        return $entry->created_by === $user->id || $user->hasRole(...UserRole::approvers())
            ? Response::allow()
            : Response::deny("Only the person who prepared this entry, or an approver, can {$verb} it.");
    }
}
