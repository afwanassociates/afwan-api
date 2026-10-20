<?php

namespace App\Http\Resources;

use App\Support\Workflow;
use Illuminate\Http\Request;

/**
 * A passport in the company report (read only): the passport list's shape
 * (with the six-step workflow) plus the overview's latest_status. It carries
 * no action permissions, only whether the viewer may edit passport details.
 */
class CompanyPassportResource extends PassportListResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        unset($data['can']);

        return [
            ...$data,
            'latest_status' => app(Workflow::class)->latestStatus($this->resource),
            'can_edit_passport' => $request->user()?->can('update', $this->resource) ?? false,
        ];
    }
}
