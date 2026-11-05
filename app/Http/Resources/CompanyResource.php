<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'country' => [
                'code' => $this->country->code,
                'name' => $this->country->name,
            ],
            'quota' => $this->quota,
            'is_active' => $this->is_active,
            'passports_count' => $this->whenCounted('passportEntries'),
            // Management list only (withDeletable): no passport, deleted ones included.
            'can_delete' => $this->when(
                array_key_exists('has_any_passports', $this->resource->getAttributes()),
                fn () => $this->resource->canBeDeleted(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
