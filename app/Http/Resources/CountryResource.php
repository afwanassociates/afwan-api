<?php

namespace App\Http\Resources;

use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full country record for the admin screens.
 *
 * @mixin Country
 */
class CountryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'is_pinned' => $this->is_pinned,
            'sort_order' => $this->sort_order,
            'companies_count' => $this->whenCounted('companies'),
            'passport_entries_count' => $this->whenCounted('passportEntries'),
            'updated_by' => $this->updated_by,
            'updated_at' => $this->updated_at,
        ];
    }
}
