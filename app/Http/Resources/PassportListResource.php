<?php

namespace App\Http\Resources;

use App\Models\PassportEntry;

/**
 * A passport in a list: the details shape, but of the scan copy only has_scan.
 *
 * @mixin PassportEntry
 */
class PassportListResource extends PassportEntryResource
{
    /**
     * @return array<string, mixed>
     */
    protected function scanFields(): array
    {
        return $this->scanFlag($this->resource);
    }
}
