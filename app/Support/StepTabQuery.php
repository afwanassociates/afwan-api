<?php

namespace App\Support;

use App\Models\PassportEntry;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one definition of which passports are in a step page's tab. The step
 * list, its tab counts and /workflow/summary all build on membership() and
 * counts(), so their numbers cannot drift apart.
 *
 * A tab (App\Support\Workflow::tabs) holds record states, read from the
 * stored workflow position (current_stage, stage_status):
 *
 * - waiting: the earlier steps are completed and this step has no record yet;
 * - in_process / rejected: this step's latest record has that status;
 * - completed: the passport is past this step;
 * - next_waiting: completed, and the next step has no record yet;
 * - previous_open: still at the step before, in any state.
 *
 * A tab with drop_when_flight_done (Visa issued, BMET cleared) leaves out
 * passports whose workflow is completed (flight confirmed).
 *
 * For the first step after medical (calling) every at-step state also needs
 * a fit medical still valid today (Dhaka). Soft-deleted and returned passports
 * are never included, and unfit passports never reach a record step.
 */
class StepTabQuery
{
    public function __construct(protected Workflow $workflow) {}

    /**
     * Passports in the step's tab (no search, no order, no eager loading).
     *
     * @return Builder<PassportEntry>
     */
    public function membership(string $step, string $tab): Builder
    {
        $definition = collect($this->workflow->tabs($step))->firstWhere('key', $tab)
            ?? throw new \InvalidArgumentException("Unknown tab {$tab}.");
        $states = $definition['states'];
        $stages = $this->workflow->stages();
        $later = array_slice($stages, (int) array_search($step, $stages, true) + 1);
        $atStep = array_values(array_intersect($states, ['waiting', 'in_process', 'rejected']));
        $isFirst = $step === ($this->workflow->recordStepKeys()[0] ?? null);
        $previous = $this->workflow->recordStepsBefore($step);
        $previous = $previous === [] ? null : end($previous);
        $today = LocalDate::today()->toDateString();

        return PassportEntry::query()
            ->notReturned()
            ->withCurrentMedical()
            ->where(fn (Builder $query) => $query
                ->when($atStep !== [], fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                    ->where('passport_entries.current_stage', $step)
                    ->whereIn('passport_entries.stage_status', $atStep)
                    ->when($isFirst, fn (Builder $query) => $query->where(PassportEntry::CURRENT_MEDICAL.'.valid_until', '>=', $today))))
                ->when(in_array('completed', $states, true), fn (Builder $query) => $query->orWhereIn('passport_entries.current_stage', $later))
                ->when(in_array('next_waiting', $states, true) && isset($later[0]), fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                    ->where('passport_entries.current_stage', $later[0])
                    ->where('passport_entries.stage_status', 'waiting')))
                // Still at the step before, in any state (e.g. Flight Pending while BMET is open).
                ->when(in_array('previous_open', $states, true) && $previous !== null, fn (Builder $query) => $query->orWhere('passport_entries.current_stage', $previous)))
            ->when($definition['drop_when_flight_done'], fn (Builder $query) => $query->where('passport_entries.current_stage', '<>', Workflow::COMPLETED));
    }

    /**
     * Passports per tab for the given steps (every record step by default),
     * in one query. $filter narrows every tab the same way (e.g. a search).
     *
     * @param  list<string>|null  $steps
     * @param  (Closure(Builder<PassportEntry>): mixed)|null  $filter
     * @return array<string, array<string, int>>
     */
    public function counts(?array $steps = null, ?Closure $filter = null): array
    {
        $steps ??= $this->workflow->recordStepKeys();
        $union = null;
        $counts = [];

        foreach ($steps as $step) {
            foreach ($this->workflow->tabs($step) as $tab) {
                $counts[$step][$tab['key']] = 0;

                $query = $this->membership($step, $tab['key'])
                    ->when($filter, fn (Builder $query) => $filter($query))
                    ->toBase()
                    ->select([])
                    ->selectRaw('? as step_key, ? as tab_key, count(*) as total', [$step, $tab['key']]);

                $union = $union === null ? $query : $union->unionAll($query);
            }
        }

        foreach ($union?->get() ?? [] as $row) {
            $counts[$row->step_key][$row->tab_key] = (int) $row->total;
        }

        return $counts;
    }
}
