<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agents\StoreRecruitmentAgentRequest;
use App\Http\Requests\Agents\UpdateRecruitmentAgentRequest;
use App\Http\Resources\RecruitmentAgentResource;
use App\Models\RecruitmentAgent;
use App\Support\AgentReport;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The recruitment agents page (the operations head) and the agent dropdown (the
 * passport team and the accounts team). Agents are never deleted: deactivate one.
 */
class RecruitmentAgentController extends Controller
{
    /**
     * Every agent, active and inactive, with its current and counted passports.
     */
    public function index(Request $request, AgentReport $report): AnonymousResourceCollection
    {
        Gate::authorize('manage', RecruitmentAgent::class);

        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $search = trim((string) $request->input('q'));
        $counted = $report->countedByAgent();

        $agents = RecruitmentAgent::query()
            ->withCount(['assignments as current_count' => fn (Builder $q) => $q->current()->whereHas('passportEntry')])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', Like::contains($search))
                ->orWhere('code', 'like', Like::contains($search))
                ->orWhere('rl_number', 'like', Like::contains($search))))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('is_own')
            ->orderBy('code')
            ->get()
            ->each(fn (RecruitmentAgent $agent) => $agent->setAttribute('counted_count', $counted[$agent->id] ?? 0));

        return RecruitmentAgentResource::collection($agents);
    }

    /**
     * Up to 20 active agents matching the search (dropdowns).
     */
    public function lookup(Request $request): JsonResponse
    {
        Gate::authorize('lookup', RecruitmentAgent::class);

        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Cash payments: partner agents only (the company never pays itself).
            'partners' => ['sometimes', 'boolean'],
        ]);
        $search = trim((string) $request->input('q'));

        $agents = RecruitmentAgent::query()
            ->active()
            ->when($request->boolean('partners'), fn (Builder $q) => $q->partners())
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', Like::contains($search))
                ->orWhere('code', 'like', Like::contains($search))
                ->orWhere('rl_number', 'like', Like::contains($search))))
            ->ownFirst()
            ->limit(20)
            ->get()
            ->map(fn (RecruitmentAgent $agent) => RecruitmentAgentResource::summary($agent));

        return response()->json(['data' => $agents]);
    }

    public function store(StoreRecruitmentAgentRequest $request): RecruitmentAgentResource
    {
        Gate::authorize('create', RecruitmentAgent::class);

        $agent = DB::transaction(function () use ($request) {
            $agent = new RecruitmentAgent($request->validated());
            $agent->is_active = true;
            if ($request->boolean('is_own')) {
                // Two at once must not both become the Own agent.
                if (RecruitmentAgent::query()->where('is_own', true)->lockForUpdate()->exists()) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['is_own' => 'The Own agent (our company) already exists.']);
                }
                $agent->is_own = true;
            }
            $agent->created_by = $request->user()->id;
            $agent->save();

            return $agent;
        });

        return new RecruitmentAgentResource($agent->setAttribute('current_count', 0)->setAttribute('counted_count', 0));
    }

    public function update(UpdateRecruitmentAgentRequest $request, RecruitmentAgent $recruitmentAgent, AgentReport $report): RecruitmentAgentResource
    {
        Gate::authorize('update', $recruitmentAgent);

        $recruitmentAgent->fill($request->safe()->except('is_active'));
        if ($request->has('is_active')) {
            $recruitmentAgent->is_active = $request->boolean('is_active');
        }
        $recruitmentAgent->save();

        $recruitmentAgent->loadCount(['assignments as current_count' => fn (Builder $q) => $q->current()->whereHas('passportEntry')]);
        $recruitmentAgent->setAttribute('counted_count', $report->countedByAgent($recruitmentAgent->id)[$recruitmentAgent->id] ?? 0);

        return new RecruitmentAgentResource($recruitmentAgent);
    }
}
