<?php

namespace App\Http\Controllers\DataEntry;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\UploadPassportScanRequest;
use App\Http\Resources\PassportEntryResource;
use App\Models\PassportEntry;
use App\Services\PassportScanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The passport scan copy. Every route serving or uploading a scan points
 * here, so they cannot drift apart.
 */
class PassportScanController extends Controller
{
    public function __construct(protected PassportScanService $scans) {}

    /**
     * Stream the scan from the private disk: inline by default, as a download
     * with ?download=1. Never cached.
     */
    public function show(Request $request, PassportEntry $passport): StreamedResponse|JsonResponse
    {
        Gate::authorize('viewScan', $passport);

        if (! $this->scans->exists($passport)) {
            return response()->json(['message' => 'This passport has no scan copy yet.'], 404);
        }

        $headers = [
            'Content-Type' => $passport->scan_mime,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $name = $passport->scan_original_name ?: basename($passport->scan_path);

        return $request->boolean('download')
            ? $this->scans->disk()->download($passport->scan_path, $name, $headers)
            : $this->scans->disk()->response($passport->scan_path, $name, $headers);
    }

    /**
     * Upload or replace the scan. Returns the passport details.
     */
    public function store(UploadPassportScanRequest $request, PassportEntry $passport): PassportEntryResource
    {
        Gate::authorize('uploadScan', $passport);

        $this->scans->store($passport, $request->file('scan'));

        return new PassportEntryResource($passport->load(PassportEntryResource::RELATIONS));
    }
}
