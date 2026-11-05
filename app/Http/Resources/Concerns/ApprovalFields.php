<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Request;

/**
 * The approval part of an accounts entry: status, who prepared, approved or
 * returned it, and what the current user may do with it.
 */
trait ApprovalFields
{
    /**
     * @return array<string, mixed>
     */
    protected function approvalFields(Request $request): array
    {
        $user = $request->user();

        return [
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'prepared_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'updated_by' => $this->whenLoaded('updater', fn () => $this->updater?->name),
            'approved_by' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'approved_at' => $this->approved_at,
            'returned_by' => $this->whenLoaded('returner', fn () => $this->returner?->name),
            'returned_at' => $this->returned_at,
            'return_reason' => $this->return_reason,
            'can' => [
                'update' => (bool) $user?->can('update', $this->resource),
                'delete' => (bool) $user?->can('delete', $this->resource),
                'approve' => (bool) $user?->can('approve', $this->resource),
                'send_back' => (bool) $user?->can('sendBack', $this->resource),
            ],
        ];
    }
}
