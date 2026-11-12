<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Party;
use App\Models\PartyCompanyRate;
use App\Models\PassportEntry;
use App\Support\Like;
use App\Support\PassportPricing;
use App\Support\Workflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The payment report (accounts head and super admin), by reference, then
 * company, then passport:
 *
 * - each reference (the party who gave the passports) with its totals;
 * - each company of that reference with the amount the reference paid for it
 *   (the receipts' company lines: received = approved, waiting = pending or
 *   returned) and its passport counts;
 * - each passport with its status, price, due and the receipts behind it. A
 *   company line pays one amount for several passports, so a passport's amount
 *   is its share of that line, split by the passports' prices (evenly while one
 *   has no price).
 *
 * Price (App\Support\PassportPricing): locked on the passport. A passport's due
 * = price - its received and waiting shares; a returned passport costs nothing
 * (what was paid for it shows as a credit); one without a price has no due.
 * Company, reference and summary dues add up the passports' dues (null when no
 * passport has one).
 *
 * Status as on a party's ledger: paid (an approved full payment), pending (the
 * full payment waits for approval or was returned), part paid or unpaid.
 *
 * Filters: q (name, passport number or entry no), reference_id, company_id,
 * status. Counts and amounts cover every filter except status, so the status
 * tabs keep their numbers; status picks the references, companies and
 * passports listed. Paginated by reference.
 */
class PaymentReportController extends Controller
{
    private const STATUSES = ['paid', 'pending', 'part_paid', 'unpaid'];

    public function __invoke(Request $request, Workflow $workflow): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'reference_id' => ['nullable', 'integer', Rule::exists('parties', 'id')],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $wanted = $filters['status'] ?? null;
        $statusSql = self::statusSql();
        $base = $this->filtered($filters);

        // References with a passport matching every filter (status too).
        $page = Party::query()
            ->whereIn('id', (clone $base)
                ->when($wanted, fn (Builder $q, string $s) => $q->whereRaw("{$statusSql} = ?", [$s]))
                ->select('passport_entries.reference_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 20, ['id', 'code', 'name', 'kind', 'mobile'])
            ->withQueryString();
        $referenceIds = $page->getCollection()->pluck('id')->all();

        // Their passports (every filter but status), for the counts and the lists.
        $passports = (clone $base)
            ->whereIn('passport_entries.reference_id', $referenceIds)
            ->select('passport_entries.*')
            ->selectRaw("{$statusSql} as payment_status")
            ->with(['company' => fn ($q) => $q->select('id', 'name', 'country_code', 'rate')])
            ->orderBy('passport_entries.entry_no')
            ->get();
        $receipts = $this->receipts($passports->pluck('id')->all());
        $lines = $this->lineAmounts(fn (QueryBuilder $q) => $q->whereIn('r.party_id', $referenceIds)
            ->when($filters['company_id'] ?? null, fn (QueryBuilder $q, int $id) => $q->where('l.company_id', $id)))
            ->groupBy(fn (object $row) => "{$row->party_id}-{$row->company_id}");
        $referenceRates = PartyCompanyRate::query()->whereIn('party_id', $referenceIds)->get()
            ->keyBy(fn (PartyCompanyRate $r) => "{$r->party_id}-{$r->company_id}");

        $data = $page->getCollection()->map(function (Party $reference) use ($passports, $receipts, $lines, $referenceRates, $wanted, $workflow) {
            $mine = $passports->where('reference_id', $reference->id);

            $companies = $mine->groupBy('company_id')
                ->map(function (Collection $rows, int|string $companyId) use ($reference, $receipts, $lines, $referenceRates, $wanted, $workflow) {
                    $all = $rows->map(fn (PassportEntry $p) => $this->passportRow($p, $receipts, $workflow))->values();
                    $listed = $wanted ? $all->where('status', $wanted)->values() : $all;
                    if ($listed->isEmpty()) {
                        return null;
                    }
                    $company = $rows->first()->company;
                    $amounts = self::amounts($lines->get("{$reference->id}-{$companyId}", collect()));
                    $own = $referenceRates->get("{$reference->id}-{$companyId}");

                    return [
                        'company' => [
                            'id' => (int) $companyId,
                            'name' => $company?->name,
                            'country_code' => $company?->country_code,
                        ],
                        'counts' => self::counts($rows->pluck('payment_status')),
                        // The rates now (passports keep the price they were given).
                        'company_rate' => $company?->rate !== null ? (float) $company->rate : null,
                        'reference_rate' => $own !== null ? (float) $own->rate : null,
                        ...$amounts,
                        ...self::dues($all),
                        'passports' => $listed,
                    ];
                })
                ->filter()
                ->sortBy(fn (array $c) => mb_strtolower((string) $c['company']['name']))
                ->values();

            return [
                'reference' => [
                    'id' => $reference->id,
                    'code' => $reference->code,
                    'name' => $reference->name,
                    'type_label' => $reference->kind?->label(),
                    'mobile' => $reference->mobile,
                ],
                'counts' => self::counts($mine->pluck('payment_status')),
                'paid_amount' => round($companies->sum('paid_amount'), 2),
                'pending_amount' => round($companies->sum('pending_amount'), 2),
                'price_total' => round($companies->sum('price_total'), 2),
                'due' => self::sumKnown($companies->pluck('due')),
                'no_price' => (int) $companies->sum('no_price'),
                'companies' => $companies,
            ];
        });

        return response()->json([
            'data' => $data->values()->all(),
            'summary' => $this->summary($base),
            'filters' => [
                'reference' => $this->filterReference($filters['reference_id'] ?? null),
                'company' => isset($filters['company_id'])
                    ? Company::withTrashed()->select('id', 'name', 'country_code')->find($filters['company_id'])?->only(['id', 'name', 'country_code'])
                    : null,
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, Collection<int, array<string, mixed>>>  $receipts
     * @return array<string, mixed>
     */
    private function passportRow(PassportEntry $p, Collection $receipts, Workflow $workflow): array
    {
        $list = $receipts->get($p->id, collect())->values();
        $approved = ApprovalStatus::Approved->value;
        $paid = round($list->where('status', $approved)->sum('share'), 2);
        $pending = round($list->where('status', '!=', $approved)->sum('share'), 2);
        $price = self::effectivePrice($p);
        $due = $price === null ? null : round($price - $paid - $pending, 2);
        $status = $p->getAttribute('payment_status');

        return [
            'id' => $p->id,
            'entry_no' => $p->entry_no,
            'passport_name' => $p->passport_name,
            'passport_number' => $p->passport_number,
            'stage' => $p->current_return_id !== null ? 'Returned'
                : ($p->current_stage ? $workflow->label($p->current_stage) : null),
            'status' => $status,
            'is_returned' => $p->current_return_id !== null,
            'price' => $p->price !== null ? (float) $p->price : null,
            'price_source' => $p->price_source,
            'paid_amount' => $paid,
            'pending_amount' => $pending,
            // Negative: paid more than the price (or paid for a returned passport).
            'due' => $due,
            // Marked fully paid for less than its price.
            'short' => in_array($status, ['paid', 'pending'], true) && $due !== null && $due > 0.004,
            'receipts' => $list,
        ];
    }

    /** @return array{id: int, name: string, type: string|null, type_label: string|null}|null */
    private function filterReference(?int $id): ?array
    {
        $party = $id ? Party::query()->find($id, ['id', 'name', 'kind']) : null;

        return $party ? ['id' => $party->id, 'name' => $party->name, 'type' => $party->kind?->value, 'type_label' => $party->kind?->label()] : null;
    }

    /**
     * Passports with a reference, not deleted, matching the filters other than status.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<PassportEntry>
     */
    private function filtered(array $filters): Builder
    {
        $search = trim((string) ($filters['q'] ?? ''));

        return PassportEntry::query()
            ->whereNotNull('passport_entries.reference_id')
            ->when($filters['reference_id'] ?? null, fn (Builder $q, int $id) => $q->where('passport_entries.reference_id', $id))
            ->when($filters['company_id'] ?? null, fn (Builder $q, int $id) => $q->where('passport_entries.company_id', $id))
            ->when($search !== '', function (Builder $q) use ($search) {
                $pattern = Like::contains($search);
                $q->where(fn (Builder $q) => $q
                    ->where('passport_entries.passport_name', 'like', $pattern)
                    ->orWhere('passport_entries.passport_number', 'like', $pattern)
                    ->when(ctype_digit($search), fn (Builder $q) => $q->orWhere('passport_entries.entry_no', (int) $search)));
            });
    }

    /**
     * Status counts over the filtered passports, and the amounts their references
     * paid for their companies.
     *
     * @param  Builder<PassportEntry>  $base
     * @return array<string, int|float|null>
     */
    private function summary(Builder $base): array
    {
        $statusSql = self::statusSql();
        $statuses = (clone $base)->toBase()->selectRaw("{$statusSql} as s, count(*) as n")->groupBy('s')->pluck('n', 's');

        // The receipts' lines for a (reference, company) pair the filtered passports have.
        $pairs = (clone $base)
            ->whereColumn('passport_entries.reference_id', 'r.party_id')
            ->whereColumn('passport_entries.company_id', 'l.company_id')
            ->toBase();

        $amounts = self::amounts($this->lineAmounts(fn (QueryBuilder $q) => $q->whereExists($pairs)));
        $prices = (clone $base)->toBase()
            ->selectRaw('sum(case when current_return_id is null then coalesce(price, 0) else 0 end) as total')
            ->selectRaw('sum(case when current_return_id is null and price is null then 1 else 0 end) as no_price')
            ->first();
        $priceTotal = round((float) ($prices->total ?? 0), 2);
        $due = (clone $base)->toBase()
            ->leftJoinSub(PassportPricing::shares(), 'sh', 'sh.passport_entry_id', '=', 'passport_entries.id')
            ->selectRaw('sum(case when passport_entries.current_return_id is not null then -coalesce(sh.paid, 0)'
                .' when passport_entries.price is not null then passport_entries.price - coalesce(sh.paid, 0) else 0 end) as due')
            ->selectRaw('sum(case when passport_entries.current_return_id is not null or passport_entries.price is not null then 1 else 0 end) as known')
            ->first();

        return [
            'total' => (int) $statuses->sum(),
            ...collect(self::STATUSES)->mapWithKeys(fn (string $s) => [$s => (int) ($statuses[$s] ?? 0)])->all(),
            ...$amounts,
            'price_total' => $priceTotal,
            'no_price' => (int) ($prices->no_price ?? 0),
            'due' => (int) ($due->known ?? 0) > 0 ? round((float) $due->due, 2) : null,
        ];
    }

    /**
     * Receipt line totals per reference, company and approval status.
     *
     * @param  callable(QueryBuilder): mixed  $scope
     * @return Collection<int, object>
     */
    private function lineAmounts(callable $scope): Collection
    {
        $query = DB::table('cash_receipt_lines as l')
            ->join('cash_receipts as r', 'r.id', '=', 'l.cash_receipt_id')
            ->selectRaw('r.party_id, l.company_id, r.status, sum(l.amount) as total, count(*) as receipts')
            ->groupBy('r.party_id', 'l.company_id', 'r.status');
        $scope($query);

        return $query->get();
    }

    /**
     * @param  Collection<int, object>  $rows  rows of lineAmounts()
     * @return array{paid_amount: float, pending_amount: float, receipt_count: int}
     */
    private static function amounts(Collection $rows): array
    {
        $approved = ApprovalStatus::Approved->value;

        return [
            'paid_amount' => round((float) $rows->where('status', $approved)->sum('total'), 2),
            'pending_amount' => round((float) $rows->where('status', '!=', $approved)->sum('total'), 2),
            'receipt_count' => (int) $rows->sum('receipts'),
        ];
    }

    /** What a passport costs: its price, or nothing once returned (null: no price set). */
    private static function effectivePrice(PassportEntry $p): ?float
    {
        if ($p->current_return_id !== null) {
            return 0.0;
        }

        return $p->price !== null ? (float) $p->price : null;
    }

    /**
     * A company's price total, how many passports have no price, and the due
     * (its passports' dues).
     *
     * @param  Collection<int, array<string, mixed>>  $passports  rows of passportRow()
     * @return array{price_total: float, no_price: int, due: float|null}
     */
    private static function dues(Collection $passports): array
    {
        return [
            'price_total' => round((float) $passports->sum(fn (array $p) => $p['is_returned'] ? 0 : ($p['price'] ?? 0)), 2),
            'no_price' => $passports->filter(fn (array $p) => $p['due'] === null)->count(),
            'due' => self::sumKnown($passports->pluck('due')),
        ];
    }

    /**
     * The sum of the known values (null when none is known).
     *
     * @param  Collection<int, float|null>  $values
     */
    private static function sumKnown(Collection $values): ?float
    {
        $known = $values->filter(fn ($v) => $v !== null);

        return $known->isEmpty() ? null : round((float) $known->sum(), 2);
    }

    /**
     * @param  Collection<int, string>  $statuses
     * @return array<string, int>
     */
    private static function counts(Collection $statuses): array
    {
        return [
            'total' => $statuses->count(),
            ...collect(self::STATUSES)->mapWithKeys(fn (string $s) => [$s => $statuses->filter(fn ($v) => $v === $s)->count()])->all(),
        ];
    }

    /**
     * The receipts behind each passport, oldest first, with the passport's share.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function receipts(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DB::table('cash_receipt_passports as crp')
            ->join('cash_receipts as r', 'r.id', '=', 'crp.cash_receipt_id')
            ->join('cash_receipt_lines as l', 'l.id', '=', 'crp.cash_receipt_line_id')
            ->join('passport_entries as pe', 'pe.id', '=', 'crp.passport_entry_id')
            // Per line: how many passports, their prices' sum and how many have none.
            ->joinSub(PassportPricing::lineStats(), 'lc', 'lc.cash_receipt_line_id', '=', 'l.id')
            ->whereIn('crp.passport_entry_id', $ids)
            ->select('crp.passport_entry_id', 'r.id', 'r.receipt_no', 'r.date', 'r.status', 'l.amount', 'l.is_full_payment', 'lc.n', 'lc.price_sum', 'lc.unpriced', 'pe.price')
            ->orderBy('r.date')
            ->orderBy('r.id')
            ->get()
            ->groupBy('passport_entry_id')
            ->map(fn (Collection $rows) => $rows->map(fn (object $r) => [
                'id' => (int) $r->id,
                'receipt_no' => $r->receipt_no,
                'date' => $r->date,
                'status' => $r->status,
                'is_full_payment' => (bool) $r->is_full_payment,
                // The company line's amount and how many passports share it.
                'line_amount' => (float) $r->amount,
                'line_passports' => (int) $r->n,
                // Split by price when every passport on the line has one, else evenly.
                'split_by_price' => (int) $r->unpriced === 0 && (float) $r->price_sum > 0,
                'share' => (int) $r->unpriced === 0 && (float) $r->price_sum > 0
                    ? round((float) $r->amount * (float) $r->price / (float) $r->price_sum, 2)
                    : round((float) $r->amount / max(1, (int) $r->n), 2),
            ]));
    }

    /** The payment status of passport_entries.id in SQL (as PartyPassportsController). */
    private static function statusSql(): string
    {
        $has = fn (string $extra) => 'exists (select 1 from cash_receipt_passports crp'
            .' join cash_receipt_lines l on l.id = crp.cash_receipt_line_id'
            .' join cash_receipts r on r.id = crp.cash_receipt_id'
            ." where crp.passport_entry_id = passport_entries.id{$extra})";
        $approved = ApprovalStatus::Approved->value;

        return '(case'
            .' when '.$has(" and l.is_full_payment = 1 and r.status = '{$approved}'")." then 'paid'"
            .' when '.$has(' and l.is_full_payment = 1')." then 'pending'"
            .' when '.$has('')." then 'part_paid'"
            ." else 'unpaid' end)";
    }
}
