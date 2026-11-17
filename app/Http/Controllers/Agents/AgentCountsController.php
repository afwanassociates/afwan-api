<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Http\Resources\MygramAgentResource;
use App\Models\MygramAgent;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Support\Workflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The Passport Entry List's tab counts: passports without an approved MYGRAM agent, and
 * per agent the passports it has (its own tab). Returned passports are left out, like the
 * lists; stage limits the counts to one step (the list uses stage=passport).
 */
class AgentCountsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PassportEntry::class);

        $stage = $request->validate([
            'stage' => ['sometimes', Rule::in(app(Workflow::class)->stages())],
        ])['stage'] ?? null;

        $passports = fn (Builder $query) => $query->notReturned()
            ->when($stage, fn (Builder $q, string $s) => $q->where('current_stage', $s));

        $none = PassportEntry::query()
            ->tap($passports)
            ->whereDoesntHave('currentAgentAssignment')
            ->count();

        $perAgent = PassportAgentAssignment::query()
            ->current()
            ->whereHas('passportEntry', $passports)
            ->groupBy('mygram_agent_id')
            ->selectRaw('mygram_agent_id, COUNT(*) as passports')
            ->pluck('passports', 'mygram_agent_id');

        // Every active agent (even with 0), plus inactive ones that still have passports.
        $agents = MygramAgent::query()
            ->where(fn (Builder $q) => $q->where('is_active', true)->orWhereIn('id', $perAgent->keys()))
            ->orderBy('name')
            ->get()
            ->map(fn (MygramAgent $agent) => [
                ...MygramAgentResource::summary($agent),
                'is_active' => $agent->is_active,
                'count' => (int) ($perAgent[$agent->id] ?? 0),
            ]);

        return response()->json(['data' => [
            'none' => $none,
            'approved' => (int) $perAgent->sum(),
            'agents' => $agents,
        ]]);
    }
}
