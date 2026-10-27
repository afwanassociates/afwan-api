<?php

namespace App\Support;

use App\Models\Reference;
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
     * References with their counts, filtered and sorted. `reference_id` keeps
     * one reference; `company_id` counts only that company's passports and
     * keeps only references that have some.
     *
     * @param  array{search?: string|null, type?: string|null, reference_id?: int|string|null, company_id?: int|string|null, sort?: string|null}  $filters
     * @return Builder<Reference>
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

        return Reference::query()
            // Inner join with a company filter: references without its passports drop out.
            ->when(
                $companyId === null,
                fn (Builder $query) => $query->leftJoinSub($stats, 'stats', 'stats.reference_id', '=', 'references.id'),
                fn (Builder $query) => $query->joinSub($stats, 'stats', 'stats.reference_id', '=', 'references.id'),
            )
            ->select(['references.id', 'references.name', 'references.type', 'references.phone', 'references.is_active'])
            ->selectRaw(implode(', ', $counts))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('references.name', 'like', Like::contains($search))
                ->orWhere('references.phone', 'like', Like::contains($search))))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('references.type', $type))
            ->when($filters['reference_id'] ?? null, fn (Builder $query, int|string $id) => $query->where('references.id', (int) $id))
            ->orderBy(in_array($sort, ['name', 'type'], true) ? "references.{$sort}" : $sort, $direction)
            ->orderBy('references.name')
            ->orderBy('references.id');
    }

    /**
     * Sums of every count column over the filtered references, for the totals row.
     *
     * @param  Builder<Reference>  $query
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
    public function row(Reference $reference): array
    {
        return [
            'id' => $reference->id,
            'name' => $reference->name,
            'type' => $reference->type->value,
            'type_label' => $reference->type->label(),
            'phone' => $reference->phone,
            'is_active' => (bool) $reference->is_active,
            ...collect($this->countColumns())->mapWithKeys(fn (string $column) => [$column => (int) $reference->getAttribute($column)])->all(),
        ];
    }
}
