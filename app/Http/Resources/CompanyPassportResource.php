<?php

namespace App\Http\Resources;

use App\Support\Workflow;
use Illuminate\Http\Request;

/**
 * A passport in the company report: the passport list's shape (with the
 * six-step workflow) plus the overview's latest_status.
 */
class CompanyPassportResource extends PassportEntryResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'latest_status' => app(Workflow::class)->latestStatus($this->resource),
        ];
    }
}
