<?php

namespace App\Support;

use App\Http\Resources\RecruitmentAgentResource;
use App\Models\PassportEntry;
use App\Models\RecruitmentAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The agent-wise tabs of the passport lists (Passport Entry List, Medical and
 * every step page): "No agent", then one tab per recruitment agent with its
 * passports, the Own agent first.
 */
class AgentTabs
{
    /**
     * Count the passports of $passports by their current (approved) agent.
     *
     * @param  Builder<PassportEntry>  $passports
     * @return array{none: int, approved: int, agents: Collection<int, array<string, mixed>>}
     */
    public static function count(Builder $passports): array
    {
        $current = DB::table('passport_agent_assignments')
            ->select('recruitment_agent_id')
            ->whereColumn('passport_agent_assignments.passport_entry_id', 'passport_entries.id')
            ->where('status', 'approved')
            ->whereNull('replaced_by_id')
            ->limit(1);

        $rows = DB::query()
            ->fromSub($passports->toBase()->select([])->selectSub($current, 'agent_id'), 'p')
            ->selectRaw('agent_id, count(*) as total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $none = (int) ($rows[''] ?? 0);
        $perAgent = $rows->except([''])->map(fn ($total) => (int) $total);

        return [
            'none' => $none,
            'approved' => (int) $perAgent->sum(),
            'agents' => static::agents($perAgent),
        ];
    }

    /**
     * Every active agent (even with 0) plus inactive ones that still have passports, Own first.
     *
     * @param  Collection<int|string, int>  $perAgent  passports per agent id
     * @return Collection<int, array<string, mixed>>
     */
    public static function agents(Collection $perAgent): Collection
    {
        return RecruitmentAgent::query()
            ->where(fn (Builder $q) => $q->where('is_active', true)->orWhereIn('id', $perAgent->keys()->map(fn ($id) => (int) $id)))
            ->ownFirst()
            ->get()
            ->map(fn (RecruitmentAgent $agent) => [
                ...RecruitmentAgentResource::summary($agent),
                'is_active' => $agent->is_active,
                'count' => (int) ($perAgent[$agent->id] ?? 0),
            ])
            ->values();
    }
}
