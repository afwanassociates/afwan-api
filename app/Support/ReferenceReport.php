<?php

namespace App\Support;

use App\Enums\PartyType;
use App\Models\Party;
use Illuminate\Database\Eloquent\Builder;

/**
 * The reference-wise report: one row per reference with the company report's
 * passport counts (App\Support\CompanyReport), without quota or balance.
 * Worked out at request time; nothing is stored.
 */
class ReferenceReport
{
    public function __construct(protected CompanyReport $companies) {}

    /**
     * Count columns, in report order (the company report's).
     *
     * @return list<string>
     */
    public function countColumns(): array
    {
        return $this->companies->countColumns();
    }

    /**
     * Columns the report may be sorted by.
     *
     * @return list<string>
     */
    public function sortable(): array
    {
        return ['name', 'type', ...$this->countColumns()];
    }

    /**
     * References that have passports (deleted ones left out), with their
     * counts, filtered and sorted. `reference_id` keeps one reference;
     * `company_id` counts only that company's passports and keeps only
     * references that have some.
     *
     * @param  array{search?: string|null, type?: string|null, reference_id?: int|string|null, company_id?: int|string|null, sort?: string|null}  $filters
     * @return Builder<Party>
     */
    public function query(array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $companyId = isset($filters['company_id']) ? (int) $filters['company_id'] : null;
        $sort = (string) ($filters['sort'] ?? 'name');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $sort = ltrim($sort, '-');

        $counts = array_map(fn (string $column) => "coalesce(stats.{$column}, 0) as {$column}", $this->countColumns());
        $stats = $this->companies->passportCounts('reference_id', companyId: $companyId);

        // References are client parties (agencies and persons).
        return Party::query()
            // Inner join: references without (that company's) passports drop out.
            ->joinSub($stats, 'stats', 'stats.reference_id', '=', 'parties.id')
            ->where('parties.type', PartyType::Client->value)
            ->select(['parties.id', 'parties.code', 'parties.name', 'parties.kind', 'parties.mobile', 'parties.rl_number', 'parties.is_active'])
            ->selectRaw(implode(', ', $counts))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('parties.name', 'like', Like::contains($search))
                ->orWhere('parties.mobile', 'like', Like::contains($search))
                ->orWhere('parties.code', 'like', Like::contains($search))
                ->orWhere('parties.rl_number', 'like', Like::contains($search))))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('parties.kind', $type))
            ->when($filters['reference_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('parties.id', (int) $id))
            ->orderBy(match ($sort) {
                'name' => 'parties.name',
                'type' => 'parties.kind',
                default => $sort,
            }, $direction)
            ->orderBy('parties.name')
            ->orderBy('parties.id');
    }

    /**
     * Sums of every count column over the filtered references, for the totals row.
     *
     * @param  Builder<Party>  $query
     * @return array<string, int>
     */
    public function totals(Builder $query): array
    {
        $columns = $this->countColumns();

        $row = $query->toBase()
            ->newQuery()
            ->fromSub($query->toBase()->reorder(), 'report')
            ->selectRaw(implode(', ', array_map(fn (string $column) => "sum({$column}) as {$column}", $columns)))
            ->first();

        return collect($columns)
            ->mapWithKeys(fn (string $column) => [$column => (int) ($row->{$column} ?? 0)])
            ->all();
    }

    /**
     * One report row as an array (counts as integers).
     *
     * @return array<string, mixed>
     */
    public function row(Party $reference): array
    {
        return [
            'id' => $reference->id,
            'code' => $reference->code,
            'name' => $reference->name,
            'type' => $reference->kind?->value,
            'type_label' => $reference->kind?->label(),
            'phone' => $reference->mobile,
            'rl_number' => $reference->rl_number,
            'is_active' => (bool) $reference->is_active,
            ...collect($this->countColumns())->mapWithKeys(fn (string $column) => [$column => (int) $reference->getAttribute($column)])->all(),
        ];
    }
}
