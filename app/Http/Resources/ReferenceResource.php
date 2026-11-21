<?php

namespace App\Http\Resources;

use App\Models\Party;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client party as the passport team sees it (a passport's reference):
 * `type` is its kind (person or agency) and `phone` its mobile.
 *
 * @mixin Party
 */
class ReferenceResource extends JsonResource
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
            'code' => $this->code,
            'type' => $this->kind?->value,
            'type_label' => $this->kind?->label(),
            'name' => $this->name,
            'phone' => $this->mobile,
            'rl_number' => $this->rl_number,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'passports_count' => $this->whenCounted('passportEntries'),
            // Management list only (withDeletable): no passports and nothing in accounts.
            'can_delete' => $this->when(
                array_key_exists('has_any_passports', $this->resource->getAttributes()),
                fn () => $this->resource->canBeDeleted(),
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
