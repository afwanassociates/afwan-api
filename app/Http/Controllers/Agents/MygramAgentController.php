<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agents\StoreMygramAgentRequest;
use App\Http\Requests\Agents\UpdateMygramAgentRequest;
use App\Http\Resources\MygramAgentResource;
use App\Models\MygramAgent;
use App\Support\AgentReport;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The MYGRAM Agents page (the operations head) and the agent dropdown (the
 * passport team and the accounts team). Agents are never deleted: deactivate one.
 */
class MygramAgentController extends Controller
{
    /**
     * Every agent, active and inactive, with its current and counted passports.
     */
    public function index(Request $request, AgentReport $report): AnonymousResourceCollection
    {
        Gate::authorize('manage', MygramAgent::class);

        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $search = trim((string) $request->input('q'));
        $counted = $report->countedByAgent();

        $agents = MygramAgent::query()
            ->withCount(['assignments as current_count' => fn (Builder $q) => $q->current()->whereHas('passportEntry')])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', Like::contains($search))
                ->orWhere('code', 'like', Like::contains($search))
                ->orWhere('rl_number', 'like', Like::contains($search))))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('code')
            ->get()
            ->each(fn (MygramAgent $agent) => $agent->setAttribute('counted_count', $counted[$agent->id] ?? 0));

        return MygramAgentResource::collection($agents);
    }

    /**
     * Up to 20 active agents matching the search (dropdowns).
     */
    public function lookup(Request $request): JsonResponse
    {
        Gate::authorize('lookup', MygramAgent::class);

        $request->validate(['q' => ['sometimes', 'nullable', 'string', 'max:100']]);
        $search = trim((string) $request->input('q'));

        $agents = MygramAgent::query()
            ->active()
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('name', 'like', Like::contains($search))
                ->orWhere('code', 'like', Like::contains($search))
                ->orWhere('rl_number', 'like', Like::contains($search))))
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (MygramAgent $agent) => MygramAgentResource::summary($agent));

        return response()->json(['data' => $agents]);
    }

    public function store(StoreMygramAgentRequest $request): MygramAgentResource
    {
        Gate::authorize('create', MygramAgent::class);

        $agent = DB::transaction(function () use ($request) {
            $agent = new MygramAgent($request->validated());
            $agent->is_active = true;
            $agent->created_by = $request->user()->id;
            $agent->save();

            return $agent;
        });

        return new MygramAgentResource($agent->setAttribute('current_count', 0)->setAttribute('counted_count', 0));
    }

    public function update(UpdateMygramAgentRequest $request, MygramAgent $mygramAgent, AgentReport $report): MygramAgentResource
    {
        Gate::authorize('update', $mygramAgent);

        $mygramAgent->fill($request->safe()->except('is_active'));
        if ($request->has('is_active')) {
            $mygramAgent->is_active = $request->boolean('is_active');
        }
        $mygramAgent->save();

        $mygramAgent->loadCount(['assignments as current_count' => fn (Builder $q) => $q->current()->whereHas('passportEntry')]);
        $mygramAgent->setAttribute('counted_count', $report->countedByAgent($mygramAgent->id)[$mygramAgent->id] ?? 0);

        return new MygramAgentResource($mygramAgent);
    }
}
