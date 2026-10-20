<?php

namespace App\Http\Controllers\Accounts;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApprovalService;
use App\Support\ApprovalQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Approval of accounts entries (Prepared → Approved): the queue, the count for
 * the menu badge, and the approve / return actions. Approvers (accounts head,
 * admin, super admin) see every entry; the accounts team sees only the entries
 * they prepared ("My entries"), to fix the returned ones.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    /**
     * GET /approvals?status=pending|rejected&type=cash-receipts|… — every
     * entry of that status, oldest first.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeTeam($request);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in([ApprovalStatus::Pending->value, ApprovalStatus::Rejected->value])],
            'type' => ['sometimes', 'nullable', Rule::in(array_keys(ApprovalQueue::TYPES))],
        ]);

        $status = ApprovalStatus::from($filters['status'] ?? ApprovalStatus::Pending->value);

        return response()->json([
            'data' => ApprovalQueue::items($status, $filters['type'] ?? null, $request->user(), $this->onlyMine($request)),
        ]);
    }

    /**
     * GET /approvals/summary — for the menu badge: entries waiting (everyone's
     * for an approver, one's own for the accounts team) and one's own returned.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizeTeam($request);
        $user = $request->user();

        return response()->json(['data' => [
            'pending' => ApprovalQueue::count(ApprovalStatus::Pending, $this->onlyMine($request)),
            'returned_mine' => ApprovalQueue::count(ApprovalStatus::Rejected, $user),
            'is_approver' => $user->hasRole(...UserRole::approvers()),
        ]]);
    }

    public function approve(Request $request, string $type, int $id): JsonResponse
    {
        $entry = ApprovalQueue::find($type, $id) ?? abort(404);
        Gate::authorize('approve', $entry);

        $entry = $this->approvals->approve($entry, $request->user());

        return response()->json(['data' => ['status' => $entry->status->value, 'status_label' => $entry->status->label()]]);
    }

    /**
     * POST /approvals/{type}/{id}/return — back to the preparer, with a reason.
     */
    public function sendBack(Request $request, string $type, int $id): JsonResponse
    {
        $entry = ApprovalQueue::find($type, $id) ?? abort(404);
        Gate::authorize('sendBack', $entry);

        $reason = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Write why it is returned, so the preparer can fix it.'],
        )['reason'];

        $entry = $this->approvals->sendBack($entry, $request->user(), trim($reason));

        return response()->json(['data' => ['status' => $entry->status->value, 'status_label' => $entry->status->label()]]);
    }

    /**
     * The accounts team sees only what they prepared; approvers see everything.
     */
    private function onlyMine(Request $request): ?User
    {
        $user = $request->user();

        return $user->hasRole(...UserRole::approvers()) ? null : $user;
    }

    private function authorizeTeam(Request $request): void
    {
        abort_unless($request->user()->hasRole(...UserRole::accountsTeam()), 403);
    }
}
