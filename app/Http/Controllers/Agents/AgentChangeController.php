<?php

namespace App\Http\Controllers\Agents;

use App\Enums\AgentCountDecision;
use App\Http\Controllers\Controller;
use App\Http\Resources\AgentAssignmentResource;
use App\Models\PassportAgentAssignment;
use App\Services\AgentAssignmentService;
use App\Support\Normalize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Agent Changes (the accounts head): after a passport moved from agent A to
 * agent B, decide whether it still counts (is paid) for A.
 */
class AgentChangeController extends Controller
{
    private const RELATIONS = ['agent', 'assigner', 'approver', 'decider', 'passportEntry', 'replacedBy.agent'];

    public function __construct(private readonly AgentAssignmentService $service) {}

    /**
     * decision=waiting (default) or decided.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('decide', PassportAgentAssignment::class);

        $request->validate(['decision' => ['sometimes', Rule::in(['waiting', 'decided'])]]);
        $waiting = $request->input('decision', 'waiting') === 'waiting';

        $rows = PassportAgentAssignment::query()
            ->whereNotNull('count_decision')
            ->when(
                $waiting,
                fn ($q) => $q->where('count_decision', AgentCountDecision::Waiting->value),
                fn ($q) => $q->where('count_decision', '<>', AgentCountDecision::Waiting->value),
            )
            ->whereHas('passportEntry')
            ->with(self::RELATIONS)
            ->orderBy('id', $waiting ? 'asc' : 'desc')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return AgentAssignmentResource::collection($rows);
    }

    public function summary(): JsonResponse
    {
        Gate::authorize('decide', PassportAgentAssignment::class);

        return response()->json(['data' => [
            'waiting' => PassportAgentAssignment::query()
                ->where('count_decision', AgentCountDecision::Waiting->value)
                ->whereHas('passportEntry')
                ->count(),
        ]]);
    }

    public function decide(Request $request, PassportAgentAssignment $assignment): AgentAssignmentResource
    {
        Gate::authorize('decide', PassportAgentAssignment::class);

        $data = $request->validate([
            'counts' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['counts.required' => 'Choose Yes or No.']);

        $assignment = $this->service->decide(
            $assignment,
            (bool) $data['counts'],
            Normalize::name($data['note'] ?? null) ?: null,
            $request->user(),
        );

        return new AgentAssignmentResource($assignment->load(self::RELATIONS));
    }
}
