<?php

namespace App\Support;

use App\Models\Company;
use App\Models\PassportEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The company-wise report: one row per company with passport counts, worked
 * out at request time from the stored workflow position (current_stage and
 * stage_status). Nothing is stored. Unfit passports count only as unfit.
 */
class CompanyReport
{
    public function __construct(protected Workflow $workflow) {}

    /**
     * Count columns, in report order.
     *
     * @return list<string>
     */
    public function countColumns(): array
    {
        return [
            'total',
            'medical_fit',
            'medical_unfit',
            'medical_pending',
            'not_started',
            ...array_map(fn (string $key) => "{$key}_done", $this->workflow->recordStepKeys()),
            'in_process',
        ];
    }

    /**
     * Columns the report may be sorted by.
     *
     * @return list<string>
     */
    public function sortable(): array
    {
        return ['name', 'quota', ...$this->countColumns(), 'balance'];
    }

    /**
     * Companies with their counts, filtered and sorted. `company_id` keeps one
     * company; `reference_id` counts only that reference's passports and keeps
     * only companies that have some (the balance still uses every passport).
     *
     * @param  array{search?: string|null, country_code?: string|null, company_id?: int|string|null, reference_id?: int|string|null, sort?: string|null}  $filters
     * @return Builder<Company>
     */
    public function query(array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $referenceId = isset($filters['reference_id']) ? (int) $filters['reference_id'] : null;
        $sort = (string) ($filters['sort'] ?? 'name');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $sort = ltrim($sort, '-');

        $counts = array_map(fn (string $column) => "coalesce(stats.{$column}, 0) as {$column}", $this->countColumns());

        if ($referenceId === null) {
            $query = Company::query()
                ->leftJoinSub($this->passportCounts('company_id'), 'stats', 'stats.company_id', '=', 'companies.id');
            $allTotal = 'stats.total';
        } else {
            // Inner join: companies without passports from this reference drop out.
            $query = Company::query()
                ->joinSub($this->passportCounts('company_id', $referenceId), 'stats', 'stats.company_id', '=', 'companies.id')
                ->leftJoinSub(
                    PassportEntry::query()->toBase()->select('company_id')->selectRaw('count(*) as total')->groupBy('company_id'),
                    'all_stats', 'all_stats.company_id', '=', 'companies.id',
                );
            $allTotal = 'all_stats.total';
        }

        return $query
            ->select(['companies.id', 'companies.name', 'companies.country_code', 'companies.quota'])
            ->selectRaw(implode(', ', $counts))
            ->selectRaw("case when companies.quota is null then null else cast(companies.quota as signed) - coalesce({$allTotal}, 0) end as balance")
            ->when($search !== '', fn (Builder $query) => $query->where('companies.name', 'like', Like::contains($search)))
            ->when($filters['company_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('companies.id', (int) $id))
            ->when($filters['country_code'] ?? null, fn (Builder $query, string $code) => $query->where('companies.country_code', $code))
            ->orderBy(in_array($sort, ['name', 'quota'], true) ? "companies.{$sort}" : $sort, $direction)
            ->orderBy('companies.id');
    }

    /**
     * Sums of every count column over the filtered companies, for the totals
     * row. The balance total covers only companies with a quota.
     *
     * @param  Builder<Company>  $query
     * @return array<string, int|null>
     */
    public function totals(Builder $query): array
    {
        $columns = ['quota', ...$this->countColumns(), 'balance'];

        $row = $query->toBase()
            ->newQuery()
            ->fromSub($query->toBase()->reorder(), 'report')
            ->selectRaw(implode(', ', array_map(fn (string $column) => "sum({$column}) as {$column}", $columns)))
            ->first();

        return collect($columns)
            ->mapWithKeys(fn (string $column) => [$column => $row->{$column} === null ? null : (int) $row->{$column}])
            ->all();
    }

    /**
     * One report row as an array (counts as integers).
     *
     * @return array<string, mixed>
     */
    public function row(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'country_code' => $company->country_code,
            'quota' => $company->quota,
            ...collect($this->countColumns())->mapWithKeys(fn (string $column) => [$column => (int) $company->getAttribute($column)])->all(),
            'balance' => $company->getAttribute('balance') === null ? null : (int) $company->getAttribute('balance'),
        ];
    }

    /**
     * Passport counts per company (or per reference, by `$groupBy`), soft-deleted passports left out,
     * optionally only the passports of one reference (`$referenceId`):
     * - medical_fit: past the medical step (a fit medical, even if it has
     *   since expired); medical_unfit and medical_pending: still at medical
     *   (pending means the slip date is in); not_started: no slip date yet;
     * - <step>_done: the stage is past that step (completed counts for all);
     * - in_process: flight not confirmed and not unfit.
     */
    public function passportCounts(string $groupBy, ?int $referenceId = null): QueryBuilder
    {
        return PassportEntry::query()
            ->when($referenceId !== null, fn (Builder $query) => $query->where('reference_id', $referenceId))
            ->toBase()
            ->select($groupBy)
            ->selectRaw(implode(', ', $this->countSums()))
            ->groupBy($groupBy);
    }

    /**
     * The same counts over every passport (with or without a company), for
     * the dashboard summary.
     *
     * @return array<string, int>
     */
    public function overallCounts(): array
    {
        $row = PassportEntry::query()->toBase()->selectRaw(implode(', ', $this->countSums()))->first();

        return collect($this->countColumns())
            ->mapWithKeys(fn (string $column) => [$column => (int) ($row->{$column} ?? 0)])
            ->all();
    }

    /**
     * The SQL aggregates behind every count column (see passportCounts()).
     *
     * @return list<string>
     */
    protected function countSums(): array
    {
        $steps = $this->workflow->recordStepKeys();
        $sums = [
            'count(*) as total',
            // Past medical, or fit at medical waiting for its Online mark.
            "sum(current_stage not in ('".Workflow::PASSPORT."', 'medical') or (current_stage = 'medical' and stage_status = 'fit')) as medical_fit",
            "sum(current_stage = 'medical' and stage_status = 'unfit') as medical_unfit",
            "sum(current_stage = 'medical' and stage_status = 'pending') as medical_pending",
            "sum(current_stage = '".Workflow::PASSPORT."') as not_started",
        ];

        foreach ($steps as $index => $key) {
            $later = array_map(fn (string $stage) => "'{$stage}'", [...array_slice($steps, $index + 1), Workflow::COMPLETED]);
            $sums[] = 'sum(current_stage in ('.implode(', ', $later).")) as {$key}_done";
        }

        $sums[] = "sum(current_stage <> '".Workflow::COMPLETED."' and not (current_stage = 'medical' and stage_status = 'unfit')) as in_process";

        return $sums;
    }
}
