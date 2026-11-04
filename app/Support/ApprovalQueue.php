<?php

namespace App\Support;

use App\Enums\ApprovalStatus;
use App\Enums\TransferDirection;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\CashTransfer;
use App\Models\OwnerLoanBill;
use App\Models\PartyTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The approval queue: every accounts entry of one status (pending or
 * returned) across receipts, payments, transfers and hand-entered ledger
 * lines, oldest first, in one shape. Approvers see everyone's entries; the
 * accounts team sees only the ones they prepared ("My entries").
 */
class ApprovalQueue
{
    /**
     * URL segment => model, for the approval routes.
     *
     * @var array<string, class-string<Model>>
     */
    public const TYPES = [
        'cash-receipts' => CashReceipt::class,
        'cash-payments' => CashPayment::class,
        'cash-transfers' => CashTransfer::class,
        'party-transactions' => PartyTransaction::class,
        'owner-loan-bills' => OwnerLoanBill::class,
    ];

    /** Most entries returned at once (the queue is meant to stay short). */
    private const LIMIT = 500;

    private const PEOPLE = ['creator:id,name', 'updater:id,name', 'returner:id,name'];

    /**
     * The entry of a type by id, or null (a voucher-owned ledger line is not
     * approved on its own).
     */
    public static function find(string $type, int $id): CashReceipt|CashPayment|CashTransfer|PartyTransaction|OwnerLoanBill|null
    {
        $model = self::TYPES[$type] ?? null;
        $entry = $model ? $model::query()->find($id) : null;

        return $entry instanceof PartyTransaction && ! $entry->isManual() ? null : $entry;
    }

    /**
     * How many entries have the status; only those prepared by $preparedBy when given.
     */
    public static function count(ApprovalStatus $status, ?User $preparedBy = null): int
    {
        return array_sum(array_map(
            fn (string $model) => self::scoped($model, $status, $preparedBy)->count(),
            self::TYPES,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function items(ApprovalStatus $status, ?string $type, User $user, ?User $preparedBy = null): array
    {
        $items = [];

        foreach (self::TYPES as $key => $model) {
            if ($type !== null && $type !== $key) {
                continue;
            }

            $query = self::scoped($model, $status, $preparedBy)->with(self::PEOPLE)->limit(self::LIMIT);
            match ($model) {
                CashTransfer::class => null,
                OwnerLoanBill::class => $query->with('ownerLoan.party'),
                CashReceipt::class => $query->with(['party', 'ownerLoan:id,payment_no']),
                default => $query->with('party'),
            };

            foreach ($query->get() as $entry) {
                $items[] = [...self::describe($key, $entry), ...self::common($entry, $user)];
            }
        }

        usort($items, fn (array $a, array $b) => [$a['date'], $a['created_at']] <=> [$b['date'], $b['created_at']]);

        return array_slice($items, 0, self::LIMIT);
    }

    /**
     * @param  class-string<Model>  $model
     * @return Builder<Model>
     */
    private static function scoped(string $model, ApprovalStatus $status, ?User $preparedBy = null): Builder
    {
        return $model::query()
            ->where('status', $status->value)
            ->when($preparedBy, fn (Builder $q, User $user) => $q->where('created_by', $user->id))
            // Only hand-entered ledger lines: a line written by a receipt, payment or owner loan
            // bill is approved with it.
            ->when($model === PartyTransaction::class, fn (Builder $q) => $q->whereNull('cash_receipt_id')->whereNull('cash_payment_id')->whereNull('owner_loan_bill_id'));
    }

    /**
     * What the entry is, in the queue's words: kind, number, who, details and
     * whether money comes in or goes out.
     *
     * @return array<string, mixed>
     */
    private static function describe(string $type, Model $entry): array
    {
        return match (true) {
            $entry instanceof CashReceipt => [
                'type' => $type, 'kind' => 'Cash receive', 'reference' => $entry->receipt_no,
                'title' => 'From '.$entry->party->name.' ('.$entry->party->code.')',
                'detail' => $entry->receipt_type->label()
                    .($entry->ownerLoan ? ' of loan '.$entry->ownerLoan->payment_no : '')
                    .' · '.self::via($entry),
                'party_id' => $entry->party_id, 'direction' => 'in',
            ],
            $entry instanceof CashPayment => [
                'type' => $type, 'kind' => 'Cash payment', 'reference' => $entry->payment_no,
                'title' => 'To '.($entry->party ? $entry->party->name.' ('.$entry->party->code.')' : $entry->payee_name),
                'detail' => $entry->summary().' · '.self::via($entry),
                'party_id' => $entry->party_id, 'direction' => 'out',
            ],
            $entry instanceof CashTransfer => [
                'type' => $type, 'kind' => 'Deposit / withdrawal', 'reference' => $entry->transfer_no,
                'title' => $entry->direction->labelFor($entry->channel),
                'detail' => implode(' · ', array_filter([
                    $entry->mobile_provider
                        ? $entry->mobile_provider->label().', '.$entry->mobile_number
                        : $entry->bank_name.', '.$entry->bank_branch,
                    $entry->deposit_slip_no ? 'Slip '.$entry->deposit_slip_no : null,
                    $entry->cheque_no ? 'Cheque '.$entry->cheque_no : null,
                ])),
                'party_id' => null, 'direction' => $entry->direction === TransferDirection::Withdrawal ? 'in' : 'out',
            ],
            // A company bill the owner paid with a loan: settles the loan, no money moves.
            $entry instanceof OwnerLoanBill => [
                'type' => $type, 'kind' => 'Owner loan bill', 'reference' => $entry->bill_no ? 'Bill '.$entry->bill_no : null,
                'title' => $entry->ownerLoan->party->name.' ('.$entry->ownerLoan->party->code.') · loan '.$entry->ownerLoan->payment_no,
                'detail' => $entry->expense_category->label().' · '.$entry->purpose,
                'party_id' => $entry->ownerLoan->party_id, 'direction' => null,
                'owner_loan' => true,
                'has_copy' => $entry->copy_path !== null,
            ],
            $entry instanceof PartyTransaction => [
                'type' => $type, 'kind' => 'Ledger entry', 'reference' => null,
                'title' => $entry->type->label().' · '.$entry->party->name.' ('.$entry->party->code.')',
                'detail' => $entry->type->isDebit() ? 'Debit (Dr)' : 'Credit (Cr)',
                'party_id' => $entry->party_id, 'direction' => null,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function common(CashReceipt|CashPayment|CashTransfer|PartyTransaction|OwnerLoanBill $entry, User $user): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->date->format('Y-m-d'),
            'amount' => (float) $entry->amount,
            'note' => $entry->note,
            'status' => $entry->status->value,
            'status_label' => $entry->status->label(),
            'prepared_by' => $entry->creator?->name,
            'updated_by' => $entry->updater?->name,
            'returned_by' => $entry->returner?->name,
            'return_reason' => $entry->return_reason,
            'created_at' => $entry->created_at?->toIso8601String(),
            'can' => [
                'approve' => $user->can('approve', $entry),
                'send_back' => $user->can('sendBack', $entry),
            ],
            // Why approving is not offered, for the button's hint.
            'approve_denied' => Gate::forUser($user)->inspect('approve', $entry)->message(),
        ];
    }

    /**
     * "Cash in hand", "Bank (Sonali Bank, Motijheel)" or "Mobile banking (bKash, 01712345678)".
     */
    private static function via(CashReceipt|CashPayment $entry): string
    {
        $method = $entry->payment_method->label();

        return match (true) {
            $entry->mobile_provider !== null => "{$method} ({$entry->mobile_provider->label()}, {$entry->mobile_number})",
            $entry->bank_name !== null => "{$method} ({$entry->bank_name}, {$entry->bank_branch})",
            default => $method,
        };
    }
}
