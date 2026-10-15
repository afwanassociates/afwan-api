<?php

namespace App\Support;

use App\Enums\StepStatus;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * The work list of one record step (calling, visa, bmet, flight), by tab.
 * Which passports are in a tab is defined once, in App\Support\StepTabQuery,
 * shared with the tab counts and /workflow/summary.
 */
class StepList
{
    public function __construct(protected Workflow $workflow, protected StepTabQuery $tabs) {}

    /**
     * Each tab's total for the step, with the same filters as the list.
     *
     * @param  array{search?: string|null, company?: string|null, reference?: string|null}  $filters
     * @return array<string, int>
     */
    public function tabCounts(string $step, array $filters = []): array
    {
        return $this->tabs->counts([$step], fn (Builder $query) => $this->filter($query, $filters))[$step] ?? [];
    }

    /**
     * The search, company, reference, receive date and booking date filters.
     *
     * @param  Builder<PassportEntry>  $query
     * @param  array{search?: string|null, company?: string|null, reference?: string|null, receive_date_from?: string|null, receive_date_to?: string|null, booking_date_from?: string|null, booking_date_to?: string|null}  $filters
     */
    protected function filter(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $company = trim((string) ($filters['company'] ?? ''));
        $reference = trim((string) ($filters['reference'] ?? ''));
        $receiveDateFrom = trim((string) ($filters['receive_date_from'] ?? ''));
        $receiveDateTo = trim((string) ($filters['receive_date_to'] ?? ''));
        $bookingDateFrom = trim((string) ($filters['booking_date_from'] ?? ''));
        $bookingDateTo = trim((string) ($filters['booking_date_to'] ?? ''));

        $query
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('passport_entries.passport_name', 'like', Like::contains($search))
                ->orWhere('passport_entries.passport_number', 'like', Like::contains((string) Normalize::passportNumber($search)))))
            ->when($company !== '', fn (Builder $query) => $query->whereHas('company', fn (Builder $query) => $query->where('name', 'like', Like::contains($company))))
            ->when($reference !== '', fn (Builder $query) => $query->whereHas('reference', fn (Builder $query) => $query->where('name', 'like', Like::contains($reference))))
            ->when($receiveDateFrom !== '', fn (Builder $query) => $query->whereHas('stepRecords', fn (Builder $query) => $query
                ->whereRaw("json_unquote(json_extract(details, '$.receive_date')) >= ?", [$receiveDateFrom])))
            ->when($receiveDateTo !== '', fn (Builder $query) => $query->whereHas('stepRecords', fn (Builder $query) => $query
                ->whereRaw("json_unquote(json_extract(details, '$.receive_date')) <= ?", [$receiveDateTo])))
            ->when($bookingDateFrom !== '', fn (Builder $query) => $query->whereHas('stepRecords', fn (Builder $query) => $query
                ->whereRaw("json_unquote(json_extract(details, '$.booking_date')) >= ?", [$bookingDateFrom])))
            ->when($bookingDateTo !== '', fn (Builder $query) => $query->whereHas('stepRecords', fn (Builder $query) => $query
                ->whereRaw("json_unquote(json_extract(details, '$.booking_date')) <= ?", [$bookingDateTo])));
    }

    /**
     * The tab keys of a step (its own tabs from config, or the default ones).
     *
     * @return list<string>
     */
    public function tabKeys(string $step): array
    {
        return array_column($this->workflow->tabs($step), 'key');
    }

    /**
     * Passports in the step's tab (App\Support\StepTabQuery), filtered and in
     * the tab's order. Sorting from the request is not used.
     *
     * @param  array{search?: string|null, company?: string|null, reference?: string|null}  $filters
     * @return Builder<PassportEntry>
     */
    public function query(string $step, string $tab, array $filters = []): Builder
    {
        $definition = collect($this->workflow->tabs($step))->firstWhere('key', $tab)
            ?? throw new \InvalidArgumentException("Unknown tab {$tab}.");

        $query = $this->tabs->membership($step, $tab)
            ->with(['reference', 'company', 'currentMedical', 'stepRecords'])
            ->tap(fn (Builder $query) => $this->filter($query, $filters));

        return match ($definition['order']) {
            // stage_status_date is when the step before was completed.
            'waiting_asc' => $query->orderBy('passport_entries.stage_status_date')->orderBy('passport_entries.id'),
            // When the medical turned fit (its issue date, else its date).
            'medical_fit_asc' => $query
                ->orderByRaw('coalesce('.PassportEntry::CURRENT_MEDICAL.'.issue_date, '.PassportEntry::CURRENT_MEDICAL.'.medical_date)')
                ->orderBy('passport_entries.id'),
            // When this step was completed (its receive date, else its date).
            'completed_desc' => $query
                ->orderByDesc(PassportStepRecord::query()
                    ->selectRaw("max(coalesce(json_unquote(json_extract(details, '$.receive_date')), step_date))")
                    ->whereColumn('passport_step_records.passport_entry_id', 'passport_entries.id')
                    ->where('step_key', $step)
                    ->where('status', StepStatus::Completed->value))
                ->orderByDesc('passport_entries.id'),
            // stage_status_date is this step's latest record date: oldest first.
            'status_date_asc' => $query->orderBy('passport_entries.stage_status_date')->orderBy('passport_entries.id'),
            // stage_status_date is this step's latest record date.
            default => $query->orderByDesc('passport_entries.stage_status_date')->orderByDesc('passport_entries.id'),
        };
    }

    /**
     * When the step before this one was completed: the medical date for the
     * first step, otherwise the previous step's completion. Load
     * currentMedical and stepRecords first to avoid queries.
     */
    public function previousCompletedOn(PassportEntry $passport, string $step): ?string
    {
        $before = $this->workflow->recordStepsBefore($step);

        if ($before === []) {
            return $passport->currentMedical?->medical_date?->toDateString();
        }

        $previous = $this->workflow->latestRecords($passport)->get(end($before));

        return $previous?->status === StepStatus::Completed ? $this->workflow->completedOn($previous) : null;
    }
}
