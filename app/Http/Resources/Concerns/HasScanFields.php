<?php

namespace App\Http\Resources\Concerns;

use App\Models\PassportEntry;

/**
 * The passport scan copy as clients see it. Details responses carry the full
 * set; list responses only whether a scan exists. The storage path is never
 * included.
 */
trait HasScanFields
{
    /**
     * has_scan, scan_original_name, scan_mime, scan_size and scan_uploaded_at.
     *
     * @return array<string, mixed>
     */
    protected function scanDetails(PassportEntry $passport): array
    {
        return [
            'has_scan' => $passport->hasScan(),
            'scan_original_name' => $passport->scan_original_name,
            'scan_mime' => $passport->scan_mime,
            'scan_size' => $passport->scan_size,
            'scan_uploaded_at' => $passport->scan_uploaded_at,
        ];
    }

    /**
     * Only has_scan, for list responses.
     *
     * @return array{has_scan: bool}
     */
    protected function scanFlag(PassportEntry $passport): array
    {
        return ['has_scan' => $passport->hasScan()];
    }
}
