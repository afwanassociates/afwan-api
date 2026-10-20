<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentMethod;
use App\Enums\TransferDirection;
use App\Models\CashPayment;
use App\Models\CashReceipt;
use App\Models\CashSetting;
use App\Models\CashTransfer;
use App\Models\User;
use App\Support\LocalDate;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cash in hand: the opening balance, plus cash receipts and withdrawals from
 * a bank, minus cash payments and deposits to a bank. Only approved entries
 * count; receipts and payments made through a bank do not touch cash in hand
 * (they belong to the bank book).
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
     * Set the opening balance and its date. Refused when cash entries exist
     * before the date, or when the new balance would make a day negative.
     */
    public function setOpening(float $amount, ?string $date, User $user): CashSetting
    {
        return $this->guarded(function () use ($amount, $date, $user) {
            $first = $this->firstEntryDate();

            if ($date !== null && $first !== null && $first < $date) {
                throw ValidationException::withMessages([
                    'opening_date' => 'There are cash entries from '.self::displayDate($first).'. Choose that date or an earlier one.',
                ]);
            }

            $setting = CashSetting::current();
            $setting->opening_balance = $amount;
            $setting->opening_date = $date;
            $setting->updated_by = $user->id;
            $setting->save();

            return $setting;
        }, 'opening_balance');
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
     * The cash book for a date range: the balance brought forward, then each
     * day with its opening, lines (running balance), totals and closing.
     *
     * @return array<string, mixed>
     */
    public function report(?string $from, ?string $to): array
    {
        $opening = $this->openingCents() + ($from !== null ? array_sum($this->dailyNet(before: $from)) : 0);

        $balance = $opening;
        $totalIn = 0;
        $totalOut = 0;
        $days = [];

        foreach ($this->lines($from, $to) as $line) {
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

        foreach ($range(CashReceipt::query()->approved()->where('payment_method', PaymentMethod::Cash->value)->with('party'))->get() as $r) {
            $lines[] = $this->line($r, 'receipt', $r->receipt_no, 'Received from '.$r->party->name.' ('.$r->party->code.')',
                $r->receipt_type->label().($r->note ? ' · '.$r->note : ''), self::cents($r->amount), 0, $r->party_id);
        }

        foreach ($range(CashPayment::query()->approved()->where('payment_method', PaymentMethod::Cash->value)->with('party'))->get() as $p) {
            $who = $p->party ? $p->party->name.' ('.$p->party->code.')' : $p->payee_name;
            $detail = $p->payment_type->label().($p->purpose ? ' · '.$p->purpose : '').($p->note ? ' · '.$p->note : '');
            $lines[] = $this->line($p, 'payment', $p->payment_no, 'Paid to '.$who, $detail, 0, self::cents($p->amount), $p->party_id);
        }

        foreach ($range(CashTransfer::query()->approved())->get() as $t) {
            $bank = $t->bank_name.', '.$t->bank_branch;
            $isWithdrawal = $t->direction === TransferDirection::Withdrawal;
            $lines[] = $this->line($t, 'transfer', $t->transfer_no,
                $isWithdrawal ? 'Withdrawn from '.$bank : 'Deposited to '.$bank, $t->note,
                $isWithdrawal ? self::cents($t->amount) : 0, $isWithdrawal ? 0 : self::cents($t->amount), null);
        }

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

    /**
     * The earliest date of any cash-in-hand entry, approved or not.
     */
    private function firstEntryDate(): ?string
    {
        $dates = array_filter([
            DB::table('cash_receipts')->where('payment_method', PaymentMethod::Cash->value)->min('date'),
            DB::table('cash_payments')->where('payment_method', PaymentMethod::Cash->value)->min('date'),
            DB::table('cash_transfers')->min('date'),
        ]);

        return $dates === [] ? null : substr((string) min($dates), 0, 10);
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
