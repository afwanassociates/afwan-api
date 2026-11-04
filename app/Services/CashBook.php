<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransferDirection;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\CashSetting;
use App\Models\CashTransfer;
use App\Support\LocalDate;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash in hand: the opening balance, plus cash receipts and withdrawals from
 * a bank, minus cash payments and deposits to a bank. Only receipts and
 * payments made as cash in hand count: money received or paid by bank or
 * mobile banking never passes through the cash box. Only approved entries
 * count.
 *
 * Every cash write runs through guarded(): it locks the cash settings row,
 * writes, and rolls back if cash in hand would close below zero on any day.
 * Amounts are worked in paisa (integers) so totals never drift.
 */
class CashBook
{
    /**
     * Run a cash write and refuse it (422 on $field) if any day would close
     * with negative cash in hand. With $asIfApproved, the entry the write
     * returns is counted as if it were approved, so a pending entry that could
     * never be approved is refused when it is saved, not later.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public function guarded(Closure $write, string $field = 'amount', bool $asIfApproved = false): mixed
    {
        return DB::transaction(function () use ($write, $field, $asIfApproved) {
            CashSetting::current(lock: true);

            $result = $write();

            $this->assertNeverNegative($field, $asIfApproved && $result instanceof Model ? $result : null);

            return $result;
        });
    }

    /**
     * Cash-in-hand entries waiting for approval: how many, and the cash they
     * would bring in and take out.
     *
     * @return array{count: int, in: float, out: float}
     */
    public function pendingSummary(): array
    {
        $pending = ApprovalStatus::Pending->value;
        $cash = PaymentMethod::Cash->value;
        $receipts = DB::table('cash_receipts')->where('status', $pending)->where('payment_method', $cash)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total')->first();
        $payments = DB::table('cash_payments')->where('status', $pending)->where('payment_method', $cash)
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total')->first();
        $transfers = DB::table('cash_transfers')->where('status', $pending)
            ->selectRaw('COUNT(*) as n')
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END), 0) as cash_in', [TransferDirection::Withdrawal->value])
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE 0 END), 0) as cash_out', [TransferDirection::Deposit->value])
            ->first();

        return [
            'count' => (int) $receipts->n + (int) $payments->n + (int) $transfers->n,
            'in' => self::money(self::cents($receipts->total) + self::cents($transfers->cash_in)),
            'out' => self::money(self::cents($payments->total) + self::cents($transfers->cash_out)),
        ];
    }

    /**
     * Cash entries may not be dated before the cash opening date.
     */
    public function assertOnOrAfterOpening(string $date, string $field = 'date'): void
    {
        $opening = CashSetting::current()->opening_date?->format('Y-m-d');

        if ($opening !== null && $date < $opening) {
            throw ValidationException::withMessages([
                $field => 'Cash entries start on the cash opening date ('.self::displayDate($opening).'). Choose that date or later.',
            ]);
        }
    }

    /**
     * Cash in hand at the end of the given day (today by default).
     */
    public function balanceOn(?string $date = null): float
    {
        $date ??= LocalDate::today()->format('Y-m-d');

        return self::money($this->openingCents() + array_sum($this->dailyNet(to: $date)));
    }

    /**
     * Bank balance at the end of the given day (today by default): approved
     * bank receipts and cash deposited to the bank, minus approved bank
     * payments and cash withdrawn from the bank. There is no bank opening
     * balance, so it starts at zero.
     */
    public function bankBalanceOn(?string $date = null): float
    {
        $date ??= LocalDate::today()->format('Y-m-d');
        $bank = PaymentMethod::Bank->value;
        $transfers = $this->approvedUpTo('cash_transfers', $date)->where('channel', $bank)
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE -amount END), 0) as net', [TransferDirection::Deposit->value])
            ->value('net');

        return self::money(
            self::cents($this->approvedUpTo('cash_receipts', $date)->where('payment_method', $bank)->sum('amount'))
            - self::cents($this->approvedUpTo('cash_payments', $date)->where('payment_method', $bank)->sum('amount'))
            + self::cents($transfers)
        );
    }

    /**
     * Mobile banking balance at the end of the given day (today by default):
     * approved mobile banking receipts and cash in, minus approved mobile
     * banking payments and cash out.
     * There is no mobile banking opening balance, so it starts at zero.
     */
    public function mobileBankingBalanceOn(?string $date = null): float
    {
        $date ??= LocalDate::today()->format('Y-m-d');
        $mobile = PaymentMethod::MobileBanking->value;
        $transfers = $this->approvedUpTo('cash_transfers', $date)->where('channel', $mobile)
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE -amount END), 0) as net', [TransferDirection::Deposit->value])
            ->value('net');

        return self::money(
            self::cents($this->approvedUpTo('cash_receipts', $date)->where('payment_method', $mobile)->sum('amount'))
            - self::cents($this->approvedUpTo('cash_payments', $date)->where('payment_method', $mobile)->sum('amount'))
            + self::cents($transfers)
        );
    }

    /**
     * Approved receipts (or payments) up to the given day (today by default),
     * by any method. Transfers are not counted: they only move money between
     * cash and the bank.
     */
    public function totalOn(string $table, ?string $date = null): float
    {
        $date ??= LocalDate::today()->format('Y-m-d');

        return self::money(self::cents($this->approvedUpTo($table, $date)->sum('amount')));
    }

    private function approvedUpTo(string $table, string $date): Builder
    {
        return DB::table($table)->where('status', ApprovalStatus::Approved->value)->where('date', '<=', $date);
    }

    /**
     * A book for a date range: the balance brought forward, then each day with
     * its opening, lines (running balance), totals and closing. $book picks
     * which money: cash in hand (the hand cash book, from the cash opening
     * balance), the bank or mobile banking (each starting at zero, like their
     * balances on the summary), or null for all three together (the all cash
     * book: a deposit or withdrawal only moves money between them, so it is
     * listed as a contra line that adds nothing in or out).
     *
     * @return array<string, mixed>
     */
    public function report(?string $from, ?string $to, ?PaymentMethod $book = PaymentMethod::Cash): array
    {
        $cashOpening = fn () => $this->openingCents() + ($from !== null ? array_sum($this->dailyNet(before: $from)) : 0);
        $accountOpening = fn (PaymentMethod $account) => $from !== null ? $this->accountNetBefore($account, $from) : 0;
        $opening = match ($book) {
            PaymentMethod::Cash => $cashOpening(),
            null => $cashOpening() + $accountOpening(PaymentMethod::Bank) + $accountOpening(PaymentMethod::MobileBanking),
            default => $accountOpening($book),
        };

        $balance = $opening;
        $totalIn = 0;
        $totalOut = 0;
        $days = [];

        $lines = match ($book) {
            PaymentMethod::Cash => $this->lines($from, $to),
            null => $this->allLines($from, $to),
            default => $this->accountLines($book, $from, $to),
        };

        foreach ($lines as $line) {
            $date = $line['date'];

            if (! isset($days[$date])) {
                $days[$date] = ['date' => $date, 'opening' => self::money($balance), 'lines' => [], 'in' => 0, 'out' => 0];
            }

            $balance += $line['in'] - $line['out'];
            $days[$date]['in'] += $line['in'];
            $days[$date]['out'] += $line['out'];
            $totalIn += $line['in'];
            $totalOut += $line['out'];

            $days[$date]['lines'][] = [
                ...$line,
                'in' => self::money($line['in']),
                'out' => self::money($line['out']),
                'balance' => self::money($balance),
            ];
            $days[$date]['closing'] = self::money($balance);
        }

        return [
            'book' => $book->value ?? 'all',
            'from' => $from,
            'to' => $to,
            'opening_balance' => self::money($opening),
            'days' => array_values(array_map(fn (array $day) => [
                ...$day,
                'in' => self::money($day['in']),
                'out' => self::money($day['out']),
            ], $days)),
            'totals' => ['in' => self::money($totalIn), 'out' => self::money($totalOut)],
            'closing_balance' => self::money($balance),
        ];
    }

    /**
     * Throw if cash in hand closes below zero on any day, counting $alsoCount
     * as approved.
     */
    private function assertNeverNegative(string $field, ?Model $alsoCount = null): void
    {
        $balance = $this->openingCents();

        foreach ($this->dailyNet(also: $alsoCount) as $date => $net) {
            $balance += $net;

            if ($balance < 0) {
                throw ValidationException::withMessages([
                    $field => 'Not enough cash in hand: the balance would be −৳'.number_format(-$balance / 100, 2)
                        .' on '.self::displayDate($date).'.',
                ]);
            }
        }
    }

    /**
     * Net cash movement of approved entries per day in paisa, oldest first,
     * optionally limited to days before $before or up to $to. $also is counted
     * as approved too.
     *
     * @return array<string, int>
     */
    private function dailyNet(?string $before = null, ?string $to = null, ?Model $also = null): array
    {
        $limit = function (Builder $query) use ($before, $to, $also) {
            return $query
                ->where(fn (Builder $q) => $q->where('status', ApprovalStatus::Approved->value)
                    ->when($also && $query->from === $also->getTable(), fn (Builder $q) => $q->orWhere('id', $also->getKey())))
                ->when($before, fn (Builder $q) => $q->where('date', '<', $before))
                ->when($to, fn (Builder $q) => $q->where('date', '<=', $to))
                ->groupBy('date');
        };

        $rows = [
            ...$limit(DB::table('cash_receipts')->where('payment_method', PaymentMethod::Cash->value)
                ->selectRaw('date, SUM(amount) as net'))->get(),
            ...$limit(DB::table('cash_payments')->where('payment_method', PaymentMethod::Cash->value)
                ->selectRaw('date, -SUM(amount) as net'))->get(),
            ...$limit(DB::table('cash_transfers')
                ->selectRaw('date, SUM(CASE WHEN direction = ? THEN amount ELSE -amount END) as net', [TransferDirection::Withdrawal->value]))->get(),
        ];

        $net = [];
        foreach ($rows as $row) {
            $date = substr((string) $row->date, 0, 10);
            $net[$date] = ($net[$date] ?? 0) + self::cents($row->net);
        }
        ksort($net);

        return $net;
    }

    /**
     * Every cash-in-hand entry in the range, in book order.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(?string $from, ?string $to): array
    {
        $range = fn ($query) => $query
            ->when($from, fn ($q) => $q->where('date', '>=', $from))
            ->when($to, fn ($q) => $q->where('date', '<=', $to));

        $lines = [];

        $cash = PaymentMethod::Cash->value;

        foreach ($range(CashReceipt::query()->approved()->where('payment_method', $cash)->with('party'))->get() as $r) {
            $lines[] = $this->line($r, 'receipt', $r->receipt_no, 'Received from '.$r->party->name.' ('.$r->party->code.')',
                $r->receipt_type->label().($r->note ? ' · '.$r->note : ''), self::cents($r->amount), 0, $r->party_id);
        }

        foreach ($range(CashPayment::query()->approved()->where('payment_method', $cash)->with('party'))->get() as $p) {
            $who = $p->party ? $p->party->name.' ('.$p->party->code.')' : $p->payee_name;
            $detail = $p->summary().($p->note ? ' · '.$p->note : '');
            $lines[] = $this->line($p, 'payment', $p->payment_no, 'Paid to '.$who, $detail, 0, self::cents($p->amount), $p->party_id);
        }

        foreach ($range(CashTransfer::query()->approved())->get() as $t) {
            $bank = $t->channel === PaymentMethod::MobileBanking
                ? $t->mobile_provider?->label().' '.$t->mobile_number
                : $t->bank_name.', '.$t->bank_branch;
            $isWithdrawal = $t->direction === TransferDirection::Withdrawal;
            $lines[] = $this->line($t, 'transfer', $t->transfer_no,
                $isWithdrawal ? 'Withdrawn from '.$bank : 'Deposited to '.$bank, $t->note,
                $isWithdrawal ? self::cents($t->amount) : 0, $isWithdrawal ? 0 : self::cents($t->amount), null);
        }

        return self::inBookOrder($lines);
    }

    /**
     * Net movement (paisa) of the bank or mobile banking before the given day:
     * approved receipts and cash put in, less approved payments and cash taken out.
     */
    private function accountNetBefore(PaymentMethod $account, string $before): int
    {
        $before = fn (string $table) => DB::table($table)
            ->where('status', ApprovalStatus::Approved->value)
            ->where('date', '<', $before);
        $transfers = $before('cash_transfers')->where('channel', $account->value)
            ->selectRaw('COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE -amount END), 0) as net', [TransferDirection::Deposit->value])
            ->value('net');

        return self::cents($before('cash_receipts')->where('payment_method', $account->value)->sum('amount'))
            - self::cents($before('cash_payments')->where('payment_method', $account->value)->sum('amount'))
            + self::cents($transfers);
    }

    /**
     * Every bank (or mobile banking) entry in the range, in book order: receipts
     * and cash deposited in; payments and cash withdrawn out.
     *
     * @return list<array<string, mixed>>
     */
    private function accountLines(PaymentMethod $account, ?string $from, ?string $to): array
    {
        $range = fn ($query) => $query
            ->when($from, fn ($q) => $q->where('date', '>=', $from))
            ->when($to, fn ($q) => $q->where('date', '<=', $to));

        $lines = [];

        foreach ($range(CashReceipt::query()->approved()->where('payment_method', $account->value)->with('party'))->get() as $r) {
            $detail = implode(' · ', array_filter([$r->receipt_type->label(), self::accountOf($r), $r->note]));
            $lines[] = $this->line($r, 'receipt', $r->receipt_no, 'Received from '.$r->party->name.' ('.$r->party->code.')',
                $detail, self::cents($r->amount), 0, $r->party_id);
        }

        foreach ($range(CashPayment::query()->approved()->where('payment_method', $account->value)->with('party'))->get() as $p) {
            $who = $p->party ? $p->party->name.' ('.$p->party->code.')' : $p->payee_name;
            $detail = implode(' · ', array_filter([$p->summary(), self::accountOf($p), $p->note]));
            $lines[] = $this->line($p, 'payment', $p->payment_no, 'Paid to '.$who, $detail, 0, self::cents($p->amount), $p->party_id);
        }

        foreach ($range(CashTransfer::query()->approved()->where('channel', $account->value))->get() as $t) {
            $isDeposit = $t->direction === TransferDirection::Deposit;
            $detail = implode(' · ', array_filter([self::accountOf($t), $t->note]));
            $lines[] = $this->line($t, 'transfer', $t->transfer_no,
                $isDeposit ? 'Deposited from cash in hand' : 'Withdrawn to cash in hand', $detail ?: null,
                $isDeposit ? self::cents($t->amount) : 0, $isDeposit ? 0 : self::cents($t->amount), null);
        }

        return self::inBookOrder($lines);
    }

    /**
     * Every entry in the range across cash in hand, bank and mobile banking, in
     * book order, each with the money it went through ("via"). A deposit or
     * withdrawal is a contra line: its amount is shown, but nothing comes in
     * or goes out of the company.
     *
     * @return list<array<string, mixed>>
     */
    private function allLines(?string $from, ?string $to): array
    {
        $range = fn ($query) => $query
            ->when($from, fn ($q) => $q->where('date', '>=', $from))
            ->when($to, fn ($q) => $q->where('date', '<=', $to));

        $lines = [];

        foreach ($range(CashReceipt::query()->approved()->with('party'))->get() as $r) {
            $detail = implode(' · ', array_filter([$r->receipt_type->label(), $r->payment_method === PaymentMethod::Cash ? null : self::accountOf($r), $r->note]));
            $lines[] = [...$this->line($r, 'receipt', $r->receipt_no, 'Received from '.$r->party->name.' ('.$r->party->code.')',
                $detail, self::cents($r->amount), 0, $r->party_id), 'via' => $r->payment_method->label()];
        }

        foreach ($range(CashPayment::query()->approved()->with('party'))->get() as $p) {
            $who = $p->party ? $p->party->name.' ('.$p->party->code.')' : $p->payee_name;
            $detail = implode(' · ', array_filter([$p->summary(), $p->payment_method === PaymentMethod::Cash ? null : self::accountOf($p), $p->note]));
            $lines[] = [...$this->line($p, 'payment', $p->payment_no, 'Paid to '.$who, $detail, 0, self::cents($p->amount), $p->party_id),
                'via' => $p->payment_method->label()];
        }

        foreach ($range(CashTransfer::query()->approved())->get() as $t) {
            $isDeposit = $t->direction === TransferDirection::Deposit;
            $account = $t->channel->label();
            $detail = implode(' · ', array_filter(['Contra: ৳'.number_format((float) $t->amount, 2).' moved, total unchanged', self::accountOf($t), $t->note]));
            $lines[] = [...$this->line($t, 'transfer', $t->transfer_no,
                $isDeposit ? 'Deposited from cash in hand to '.strtolower($account) : 'Withdrawn from '.strtolower($account).' to cash in hand',
                $detail, 0, 0, null), 'via' => $isDeposit ? 'Cash in hand → '.$account : $account.' → Cash in hand', 'contra' => self::money(self::cents($t->amount))];
        }

        return self::inBookOrder($lines);
    }

    /**
     * Which bank account or mobile wallet an entry went through.
     */
    private static function accountOf(CashReceipt|CashPayment|CashTransfer $voucher): ?string
    {
        $channel = $voucher instanceof CashTransfer ? $voucher->channel : $voucher->payment_method;

        $parts = $channel === PaymentMethod::MobileBanking
            ? [$voucher->mobile_provider?->label(), $voucher->mobile_number]
            : [$voucher->bank_name, $voucher->bank_branch];

        return implode(', ', array_filter($parts)) ?: null;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private static function inBookOrder(array $lines): array
    {
        usort($lines, fn (array $a, array $b) => [$a['date'], $a['sort']] <=> [$b['date'], $b['sort']]);

        return array_map(function (array $line) {
            unset($line['sort']);

            return $line;
        }, $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(CashReceipt|CashPayment|CashTransfer $voucher, string $kind, string $reference, string $particulars, ?string $detail, int $in, int $out, ?int $partyId): array
    {
        return [
            'date' => $voucher->date->format('Y-m-d'),
            'kind' => $kind,
            'id' => $voucher->id,
            'reference' => $reference,
            'particulars' => $particulars,
            'detail' => $detail,
            'party_id' => $partyId,
            'in' => $in,
            'out' => $out,
            // Within a day: in the order the entries were made.
            'sort' => $voucher->created_at?->format('Y-m-d H:i:s').'|'.$kind.'|'.str_pad((string) $voucher->id, 10, '0', STR_PAD_LEFT),
        ];
    }

    private function openingCents(): int
    {
        return self::cents(CashSetting::current()->opening_balance);
    }

    private static function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private static function money(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private static function displayDate(string $date): string
    {
        return LocalDate::parse($date)->format('d-m-Y');
    }
}
