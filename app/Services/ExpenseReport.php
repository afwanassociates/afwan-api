<?php

namespace App\Services;

use App\Enums\ApprovalStatus;
use App\Enums\CashPaymentType;
use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use App\Support\Like;
use App\Support\LocalDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the company spent, by category: approved expense payments (paid
 * directly from cash, bank or mobile banking) and approved owner loan bills
 * (company bills an owner paid with a loan the company gave them).
 *
 * A loan to an owner is an advance, not an expense: the money left when it was
 * given, and becomes an expense only as the owner shows bills for it (the rest
 * comes back as a loan return). So nothing is counted twice: cash in hand
 * counts the loan, the expenses count its bills.
 */
class ExpenseReport
{
    public const SOURCE_DIRECT = 'direct';

    public const SOURCE_OWNER_LOAN = 'owner_loan';

    /**
     * Every approved expense, newest first, with the filters applied.
     *
     * @param  array{from?: string|null, to?: string|null, category?: string|null, source?: string|null, q?: string|null}  $filters
     */
    public function query(array $filters = []): Builder
    {
        $search = trim((string) ($filters['q'] ?? ''));

        return DB::query()
            ->fromSub($this->union(), 'expenses')
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('date', '<=', $to))
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $q->where('expense_category', $category))
            ->when($filters['source'] ?? null, fn (Builder $q, string $source) => $q->where('source', $source))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($search) {
                $pattern = Like::contains($search);
                $q->where('purpose', 'like', $pattern)
                    ->orWhere('bill_no', 'like', $pattern)
                    ->orWhere('paid_to', 'like', $pattern)
                    ->orWhere('reference', 'like', $pattern);
            }))
            ->orderByDesc('date')
            ->orderByDesc('source')
            ->orderByDesc('id');
    }

    /**
     * Totals of the filtered expenses: overall, per category (biggest first) and per source.
     *
     * @return array<string, mixed>
     */
    public function totals(Builder $query): array
    {
        $base = DB::query()->fromSub((clone $query)->reorder(), 'filtered');

        $byCategory = (clone $base)
            ->select('expense_category')
            ->selectRaw('COUNT(*) as count, SUM(amount) as amount')
            ->groupBy('expense_category')
            ->orderByDesc('amount')
            ->get()
            ->map(fn (object $row) => [
                'category' => $row->expense_category,
                'label' => ExpenseCategory::from($row->expense_category)->label(),
                'count' => (int) $row->count,
                'amount' => round((float) $row->amount, 2),
            ])
            ->values()
            ->all();

        $bySource = (clone $base)->select('source')->selectRaw('SUM(amount) as amount')->groupBy('source')->pluck('amount', 'source');

        return [
            'count' => array_sum(array_column($byCategory, 'count')),
            'amount' => round(array_sum(array_column($byCategory, 'amount')), 2),
            'direct' => round((float) ($bySource[self::SOURCE_DIRECT] ?? 0), 2),
            'owner_loan' => round((float) ($bySource[self::SOURCE_OWNER_LOAN] ?? 0), 2),
            'by_category' => $byCategory,
        ];
    }

    /**
     * Total expenses up to the given day (today by default), for the money summary.
     */
    public function totalOn(?string $date = null): float
    {
        $date ??= LocalDate::today()->format('Y-m-d');

        return round((float) DB::query()->fromSub($this->union(), 'expenses')->where('date', '<=', $date)->sum('amount'), 2);
    }

    /**
     * One expense row for the API.
     *
     * @return array<string, mixed>
     */
    public static function row(object $row): array
    {
        $isLoan = $row->source === self::SOURCE_OWNER_LOAN;

        return [
            'key' => "{$row->source}-{$row->id}",
            'id' => (int) $row->id,
            'source' => $row->source,
            'source_label' => $isLoan ? 'Owner loan '.$row->reference : 'Paid directly',
            'date' => $row->date,
            'expense_category' => $row->expense_category,
            'expense_category_label' => ExpenseCategory::from($row->expense_category)->label(),
            'purpose' => $row->purpose,
            'bill_no' => $row->bill_no,
            'paid_to' => $row->paid_to,
            // The payment number, or the loan the bill settles.
            'reference' => $row->reference,
            'payment_method_label' => $isLoan ? 'Paid by the owner' : PaymentMethod::from($row->payment_method)->label(),
            'has_copy' => (bool) $row->has_copy,
            'amount' => round((float) $row->amount, 2),
        ];
    }

    /**
     * Every filtered expense as CSV.
     */
    public function csv(Builder $query): StreamedResponse
    {
        $filename = 'expenses-'.LocalDate::today()->format('d-m-Y').'.csv';
        $cell = fn (mixed $value) => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'{$value}" : $value;

        return response()->streamDownload(function () use ($query, $cell) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Category', 'Description', 'Bill no.', 'Paid to', 'Source', 'Paid via', 'Amount'], escape: '');

            foreach ($query->cursor() as $row) {
                $r = self::row($row);
                fputcsv($out, array_map($cell, [
                    LocalDate::parse($r['date'])->format('d-m-Y'),
                    $r['expense_category_label'],
                    $r['purpose'],
                    $r['bill_no'],
                    $r['paid_to'],
                    $r['source_label'],
                    $r['payment_method_label'],
                    number_format($r['amount'], 2, '.', ''),
                ]), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Approved expense payments and approved owner loan bills, in one shape.
     */
    private function union(): Builder
    {
        $approved = ApprovalStatus::Approved->value;

        $payments = DB::table('cash_payments')
            ->where('status', $approved)
            ->where('payment_type', CashPaymentType::Expense->value)
            ->selectRaw("'".self::SOURCE_DIRECT."' as source")
            ->addSelect([
                'id', 'date', 'expense_category', 'purpose', 'bill_no', 'amount',
                'payee_name as paid_to', 'payment_no as reference', 'payment_method',
            ])
            ->selectRaw('0 as has_copy');

        $bills = DB::table('owner_loan_bills as b')
            ->join('cash_payments as loan', 'loan.id', '=', 'b.owner_loan_id')
            ->join('parties as owner', 'owner.id', '=', 'loan.party_id')
            ->where('b.status', $approved)
            ->selectRaw("'".self::SOURCE_OWNER_LOAN."' as source")
            ->addSelect([
                'b.id', 'b.date', 'b.expense_category', 'b.purpose', 'b.bill_no', 'b.amount',
                'owner.name as paid_to', 'loan.payment_no as reference',
            ])
            ->selectRaw('NULL as payment_method')
            ->selectRaw('b.copy_path IS NOT NULL as has_copy');

        return $payments->unionAll($bills);
    }
}
