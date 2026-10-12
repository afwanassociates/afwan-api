<?php

namespace App\Http\Resources;

use App\Models\PassportEntry;
use Illuminate\Http\Request;

/**
 * A passport in a list: the details shape, but of the scan copy only has_scan
 * and without latest_status.
 *
 * @mixin PassportEntry
 */
class PassportListResource extends PassportEntryResource
{
    /**
     * The details shape without the details-only latest_status.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        unset($data['latest_status']);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    protected function scanFields(): array
    {
        return $this->scanFlag($this->resource);
    }
}
