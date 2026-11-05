<?php

namespace App\Support;

use App\Enums\ApprovalStatus;
use App\Models\Party;
use App\Models\PartyTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The ledger of one party for a date range: the balance brought forward, each
 * approved line with its running balance, and the closing balance. Balances
 * are positive when the party owes us (debit) and negative when we owe them.
 * Lines waiting for approval (or returned) are listed apart, under "pending";
 * they do not count.
 */
class PartyLedger
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Party $party, ?string $from, ?string $to): array
    {
        $opening = $party->signedOpeningBalance();

        if ($from !== null) {
            $before = PartyTransaction::query()
                ->approved()
                ->where('party_id', $party->id)
                ->where('date', '<', $from)
                ->get(['type', 'amount']);

            foreach ($before as $line) {
                $opening += self::signed($line);
            }
        }

        $lines = PartyTransaction::query()
            ->approved()
            ->where('party_id', $party->id)
            ->when($from, fn ($q) => $q->where('date', '>=', $from))
            ->when($to, fn ($q) => $q->where('date', '<=', $to))
            ->with(['cashReceipt:id,receipt_no', 'cashPayment:id,payment_no'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $balance = $opening;
        $debit = 0.0;
        $credit = 0.0;
        $rows = [];

        foreach ($lines as $line) {
            $amount = (float) $line->amount;
            $balance += self::signed($line);
            if ($line->type->isDebit()) {
                $debit += $amount;
            } else {
                $credit += $amount;
            }

            $rows[] = [
                'id' => $line->id,
                'date' => $line->date->format('Y-m-d'),
                'type' => $line->type->value,
                'type_label' => $line->type->label(),
                'note' => $line->note,
                'reference' => $line->cashReceipt?->receipt_no ?? $line->cashPayment?->payment_no,
                'cash_receipt_id' => $line->cash_receipt_id,
                'cash_payment_id' => $line->cash_payment_id,
                'is_manual' => $line->isManual(),
                'debit' => $line->type->isDebit() ? self::money($amount) : 0.0,
                'credit' => $line->type->isDebit() ? 0.0 : self::money($amount),
                'balance' => self::money($balance),
                'can' => self::can($line),
            ];
        }

        $pending = PartyTransaction::query()
            ->where('party_id', $party->id)
            ->where('status', '!=', ApprovalStatus::Approved->value)
            ->with(['cashReceipt.returner:id,name', 'cashPayment.returner:id,name', 'creator:id,name', 'returner:id,name'])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (PartyTransaction $line) => [
                // A receipt's or payment's line is returned through that voucher.
                ...self::returned($line->cashReceipt ?? $line->cashPayment ?? $line),
                'id' => $line->id,
                'date' => $line->date->format('Y-m-d'),
                'type' => $line->type->value,
                'type_label' => $line->type->label(),
                'note' => $line->note,
                'reference' => $line->cashReceipt?->receipt_no ?? $line->cashPayment?->payment_no,
                'cash_receipt_id' => $line->cash_receipt_id,
                'cash_payment_id' => $line->cash_payment_id,
                'is_manual' => $line->isManual(),
                'debit' => $line->type->isDebit() ? self::money((float) $line->amount) : 0.0,
                'credit' => $line->type->isDebit() ? 0.0 : self::money((float) $line->amount),
                'status' => $line->status->value,
                'status_label' => $line->status->label(),
                'prepared_by' => $line->creator?->name,
                'can' => self::can($line),
            ])
            ->all();

        return [
            'from' => $from,
            'to' => $to,
            'opening_balance' => self::money($opening),
            'lines' => $rows,
            'totals' => ['debit' => self::money($debit), 'credit' => self::money($credit)],
            'closing_balance' => self::money($balance),
            'pending' => $pending,
        ];
    }

    /**
     * What the current user may do with a line (hand-entered lines only; the
     * others change through their receipt or payment).
     *
     * @return array<string, bool>
     */
    private static function can(PartyTransaction $line): array
    {
        $user = Auth::user();

        return [
            'update' => (bool) $user?->can('update', $line),
            'delete' => (bool) $user?->can('delete', $line),
            'approve' => (bool) $user?->can('approve', $line),
            'send_back' => (bool) $user?->can('sendBack', $line),
        ];
    }

    /**
     * @return array{returned_by: ?string, return_reason: ?string}
     */
    private static function returned(Model $entry): array
    {
        return ['returned_by' => $entry->returner?->name, 'return_reason' => $entry->return_reason];
    }

    /**
     * The line's effect on the balance: + for a debit, − for a credit.
     */
    private static function signed(PartyTransaction $line): float
    {
        return $line->type->isDebit() ? (float) $line->amount : -(float) $line->amount;
    }

    private static function money(float $value): float
    {
        return round($value, 2);
    }
}
