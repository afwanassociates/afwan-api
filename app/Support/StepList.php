<?php

namespace App\Support;

use App\Enums\StepStatus;
use App\Models\PassportEntry;
use App\Models\PassportStepRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * The work list of one record step (calling, visa, bmet, flight), by tab,
 * read from the stored workflow position (current_stage, stage_status):
 *
 * - pending: the earlier steps are completed and this step has no record
 *   yet (stage = step, waiting); for the first step the fit medical must
 *   also still be valid today. Unfit passports never get here.
 * - in_process / rejected: this step's latest record has that status.
 * - completed: this step's latest record is completed (the passport is past it).
 *
 * App\Support\Workflow::summary() counts the same tabs.
 */
class StepList
{
    public const TABS = ['pending', 'in_process', 'completed', 'rejected'];

    public function __construct(protected Workflow $workflow) {}

    /**
     * Passports in the step's tab, filtered and in the tab's order: pending by
     * how long they have waited (since the step before was completed), the
     * others newest status first. Sorting from the request is not used.
     *
     * @param  array{search?: string|null, company?: string|null, reference?: string|null}  $filters
     * @return Builder<PassportEntry>
     */
    public function query(string $step, string $tab, array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $company = trim((string) ($filters['company'] ?? ''));
        $reference = trim((string) ($filters['reference'] ?? ''));
        $stages = $this->workflow->stages();
        $later = array_slice($stages, (int) array_search($step, $stages, true) + 1);
        $isFirst = $step === ($this->workflow->recordStepKeys()[0] ?? null);
        $today = LocalDate::today()->toDateString();

        $query = PassportEntry::query()
            ->with(['reference', 'company', 'currentMedical', 'stepRecords'])
            ->select('passport_entries.*');

        match ($tab) {
            'pending' => $query->where('passport_entries.current_stage', $step)
                ->where('passport_entries.stage_status', 'waiting')
                ->when($isFirst, fn (Builder $query) => $query
                    ->withCurrentMedical()
                    ->where(PassportEntry::CURRENT_MEDICAL.'.valid_until', '>=', $today)),
            'completed' => $query->whereIn('passport_entries.current_stage', $later),
            default => $query->where('passport_entries.current_stage', $step)
                ->where('passport_entries.stage_status', $tab),
        };

        $query
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('passport_entries.passport_name', 'like', Like::contains($search))
                ->orWhere('passport_entries.passport_number', 'like', Like::contains((string) Normalize::passportNumber($search)))))
            ->when($company !== '', fn (Builder $query) => $query->whereHas('company', fn (Builder $query) => $query->where('name', 'like', Like::contains($company))))
            ->when($reference !== '', fn (Builder $query) => $query->whereHas('reference', fn (Builder $query) => $query->where('name', 'like', Like::contains($reference))));

        return match ($tab) {
            // stage_status_date is when the step before was completed.
            'pending' => $query->orderBy('passport_entries.stage_status_date')->orderBy('passport_entries.id'),
            // When this step was completed.
            'completed' => $query
                ->orderByDesc(PassportStepRecord::query()
                    ->selectRaw('max(step_date)')
                    ->whereColumn('passport_step_records.passport_entry_id', 'passport_entries.id')
                    ->where('step_key', $step)
                    ->where('status', StepStatus::Completed->value))
                ->orderByDesc('passport_entries.id'),
            // stage_status_date is this step's latest record date.
            default => $query->orderByDesc('passport_entries.stage_status_date')->orderByDesc('passport_entries.id'),
        };
    }

    /**
     * When the step before this one was completed: the medical date for the
     * first step, otherwise the previous step's completed record date. Load
     * currentMedical and stepRecords first to avoid queries.
     */
    public function previousCompletedOn(PassportEntry $passport, string $step): ?string
    {
        $before = $this->workflow->recordStepsBefore($step);

        if ($before === []) {
            return $passport->currentMedical?->medical_date?->toDateString();
        }

        $previous = $this->workflow->latestRecords($passport)->get(end($before));

        return $previous?->status === StepStatus::Completed ? $previous->step_date->toDateString() : null;
    }
}
