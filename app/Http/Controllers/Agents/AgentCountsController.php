<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\PassportEntry;
use App\Support\AgentTabs;
use App\Support\Workflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The Passport Entry List's tab counts: passports without an approved recruitment agent, and
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

        $counts = AgentTabs::count(PassportEntry::query()->tap($passports));

        return response()->json(['data' => $counts]);
    }
}
