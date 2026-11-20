<?php

namespace App\Http\Controllers\Agents;

use App\Enums\AgentAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AgentAssignmentResource;
use App\Models\PassportAgentAssignment;
use App\Models\PassportEntry;
use App\Services\AgentAssignmentService;
use App\Support\AgentReport;
use App\Support\Like;
use App\Support\LocalDate;
use App\Support\Normalize;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Agent Approvals (the operations head): approve a recruitment agent assignment or
 * return it to data entry for correction.
 */
class AgentApprovalController extends Controller
{
    private const RELATIONS = ['agent', 'assigner', 'approver', 'returner', 'passportEntry.currentAgentAssignment.agent'];

    public function __construct(private readonly AgentAssignmentService $service) {}

    /**
     * Assignments of one status (pending by default), oldest first for pending.
     * export=csv downloads every row of the tab with the same search.
     */
    public function index(Request $request): AnonymousResourceCollection|StreamedResponse
    {
        Gate::authorize('viewApprovals', PassportAgentAssignment::class);

        $request->validate([
            'status' => ['sometimes', Rule::in(AgentAssignmentStatus::values())],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'reference_id' => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:300'],
            'export' => ['sometimes', Rule::in(['csv'])],
        ]);
        $status = $request->input('status', AgentAssignmentStatus::Pending->value);
        $search = trim((string) $request->input('q'));

        $rows = PassportAgentAssignment::query()
            ->where('status', $status)
            ->whereHas('passportEntry', fn (Builder $p) => $p->when(
                $request->integer('reference_id'),
                fn (Builder $p, int $id) => $p->where('reference_id', $id),
            ))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereHas('passportEntry', fn (Builder $p) => $p
                    ->where('passport_name', 'like', Like::contains($search))
                    ->orWhere('passport_number', 'like', Like::contains((string) Normalize::passportNumber($search))))
                ->orWhereHas('agent', fn (Builder $a) => $a->where('name', 'like', Like::contains($search)))))
            ->with([...self::RELATIONS, 'passportEntry.reference', 'passportEntry.company'])
            ->orderBy('id', $status === AgentAssignmentStatus::Pending->value ? 'asc' : 'desc');

        if ($request->input('export') === 'csv') {
            return $this->csv($rows, AgentAssignmentStatus::from($status));
        }

        return AgentAssignmentResource::collection($rows->paginate($request->integer('per_page', 50))->withQueryString());
    }

    /**
     * One tab of Agent Approvals as CSV (Excel opens it: UTF-8 with a BOM).
     *
     * @param  Builder<PassportAgentAssignment>  $query
     */
    protected function csv(Builder $query, AgentAssignmentStatus $status): StreamedResponse
    {
        $filename = 'agent-approvals-'.$status->value.'-'.LocalDate::today()->format('d-m-Y').'.csv';
        // Text starting with = + - @ would run as a formula in Excel.
        $cell = fn (mixed $value) => is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'{$value}" : $value;
        $date = fn (?\DateTimeInterface $value) => $value?->format('d-m-Y');
        $at = fn (?\DateTimeInterface $value) => $value ? CarbonImmutable::instance($value)->setTimezone(LocalDate::timezone())->format('d-m-Y h:i A') : null;

        $header = ['No.', 'Passport name', 'Passport number', 'Reference', 'Company', 'Agent', 'Agent code', 'RL number', 'Change from', 'Assigned date', 'Remark', 'Assigned by', 'Stage'];
        $header = [...$header, ...match ($status) {
            AgentAssignmentStatus::Approved => ['Approved by', 'Approved at'],
            AgentAssignmentStatus::Returned => ['Returned by', 'Returned at', 'Return reason'],
            default => [],
        }];

        return response()->streamDownload(function () use ($query, $status, $header, $cell, $date, $at) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, escape: '');

            foreach ($query->lazy() as $row) {
                $passport = $row->passportEntry;
                $current = $passport?->currentAgentAssignment;

                fputcsv($out, array_map($cell, [
                    $passport?->entry_no,
                    $passport?->passport_name,
                    $passport?->passport_number,
                    $passport?->reference?->name,
                    $passport?->company?->name,
                    $row->agent?->name,
                    $row->agent?->code,
                    $row->agent?->rl_number,
                    $current && $current->id !== $row->id ? $current->agent?->name : null,
                    $date($row->assigned_date),
                    $row->remark,
                    $row->assigner?->name,
                    $passport ? (AgentReport::BUCKET_LABELS[AgentReport::bucket($passport)] ?? null) : null,
                    ...match ($status) {
                        AgentAssignmentStatus::Approved => [$row->approver?->name, $at($row->approved_at)],
                        AgentAssignmentStatus::Returned => [$row->returner?->name, $at($row->returned_at), $row->return_reason],
                        default => [],
                    },
                ]), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The references with assignments waiting for approval, and how many (most first),
     * for approving reference-wise.
     */
    public function references(): JsonResponse
    {
        Gate::authorize('viewApprovals', PassportAgentAssignment::class);

        $rows = PassportAgentAssignment::query()
            ->join('passport_entries', 'passport_entries.id', '=', 'passport_agent_assignments.passport_entry_id')
            ->leftJoin('parties', 'parties.id', '=', 'passport_entries.reference_id')
            ->whereNull('passport_entries.deleted_at')
            ->where('passport_agent_assignments.status', AgentAssignmentStatus::Pending->value)
            ->groupBy('passport_entries.reference_id', 'parties.name', 'parties.code')
            ->orderByDesc('pending')
            ->orderBy('parties.name')
            ->get([
                'passport_entries.reference_id as id',
                'parties.name as name',
                'parties.code as code',
                DB::raw('count(*) as pending'),
            ]);

        return response()->json(['data' => $rows->map(fn ($row) => [
            'id' => $row->id === null ? null : (int) $row->id,
            'name' => $row->name ?? 'No reference',
            'code' => $row->code,
            'pending' => (int) $row->pending,
        ])->values()]);
    }

    /**
     * Approve every assignment waiting for one reference's passports (not the ones the
     * operations head made); the ones that cannot be are skipped with the reason.
     */
    public function approveReference(Request $request): JsonResponse
    {
        Gate::authorize('viewApprovals', PassportAgentAssignment::class);

        $referenceId = $request->validate(['reference_id' => ['required', 'integer']])['reference_id'];

        $ids = PassportAgentAssignment::query()
            ->where('status', AgentAssignmentStatus::Pending->value)
            ->whereHas('passportEntry', fn (Builder $p) => $p->where('reference_id', $referenceId))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return $this->approveMany($ids, $request);
    }

    /**
     * The pending count for the sidebar.
     */
    public function summary(): JsonResponse
    {
        Gate::authorize('viewApprovals', PassportAgentAssignment::class);

        return response()->json(['data' => [
            'pending' => PassportAgentAssignment::query()
                ->where('status', AgentAssignmentStatus::Pending->value)
                ->whereHas('passportEntry')
                ->count(),
        ]]);
    }

    public function approve(Request $request, PassportAgentAssignment $assignment): AgentAssignmentResource
    {
        Gate::authorize('approve', $assignment);

        return new AgentAssignmentResource($this->service->approve($assignment, $request->user())->load(self::RELATIONS));
    }

    /**
     * The operations head undoes the approval of a passport's agent (passport page).
     */
    public function undo(Request $request, PassportEntry $passport): AgentAssignmentResource
    {
        Gate::authorize('undoApproval', [PassportAgentAssignment::class, $passport]);

        return new AgentAssignmentResource($this->service->undoApproval($passport, $request->user())->load(self::RELATIONS));
    }

    public function sendBack(Request $request, PassportAgentAssignment $assignment): AgentAssignmentResource
    {
        Gate::authorize('approve', $assignment);

        $reason = Normalize::name($request->string('reason')->toString());
        if ($reason === null || $reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter why the assignment needs correction.']);
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'The reason may not be longer than 500 characters.']);
        }

        return new AgentAssignmentResource($this->service->sendBack($assignment, $request->user(), $reason)->load(self::RELATIONS));
    }

    /**
     * Approve many at once; the ones that cannot be are skipped with the reason.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        Gate::authorize('viewApprovals', PassportAgentAssignment::class);

        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids'];

        return $this->approveMany($ids, $request);
    }

    /**
     * Approve the assignments, skipping (with the reason) those that cannot be.
     *
     * @param  list<int>  $ids
     */
    private function approveMany(array $ids, Request $request): JsonResponse
    {
        $rows = PassportAgentAssignment::query()->whereIn('id', $ids)->with('passportEntry')->get()->keyBy('id');
        $user = $request->user();
        $describe = fn (int $id) => [
            'id' => $id,
            'passport_number' => $rows->get($id)?->passportEntry?->passport_number,
            'passport_name' => $rows->get($id)?->passportEntry?->passport_name,
        ];

        $result = $this->service->bulk($rows->keys()->all(), function (int $id) use ($rows, $user, $describe) {
            $this->service->approve($rows->get($id), $user);

            return $describe($id);
        }, $describe);

        return response()->json(['data' => $result]);
    }
}
