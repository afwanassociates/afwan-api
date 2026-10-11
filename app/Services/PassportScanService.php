<?php

namespace App\Services;

use App\Models\PassportEntry;
use App\Rules\PassportScanFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Stores passport scan copies on the private disk, the one way used by
 * passport create, update and the scan endpoint. Files are named
 * passport-scans/{passport_id}/{uuid}.{ext}; the client's file name is kept
 * only in scan_original_name and never used in a path.
 */
class PassportScanService
{
    /**
     * The private disk (storage/app/private), never served by the web server.
     */
    public const DISK = 'local';

    /**
     * Save the new scan, point the passport at it, then delete the old file.
     * Only the scan columns change on the passport.
     */
    public function store(PassportEntry $passport, UploadedFile $file): PassportEntry
    {
        $mime = PassportScanFile::realMime($file);
        $extension = PassportScanFile::TYPES[$mime][0] ?? throw new RuntimeException('Unsupported scan type.');
        $path = "passport-scans/{$passport->id}/".Str::uuid().".{$extension}";

        if ($this->disk()->putFileAs(dirname($path), $file, basename($path)) === false) {
            throw new RuntimeException('The scan could not be saved.');
        }

        $oldPath = $passport->scan_path;
        $values = [
            'scan_path' => $path,
            'scan_original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'scan_mime' => $mime,
            'scan_size' => $file->getSize(),
            'scan_uploaded_at' => now(),
        ];

        try {
            // Query builder update: a new scan is not an edit of the passport details.
            PassportEntry::withTrashed()->whereKey($passport->id)->toBase()->update($values);
        } catch (Throwable $e) {
            $this->disk()->delete($path);

            throw $e;
        }

        foreach ($values as $column => $value) {
            $passport->setAttribute($column, $value)->syncOriginalAttribute($column);
        }

        if ($oldPath !== null && $oldPath !== $path) {
            $this->disk()->delete($oldPath);
        }

        return $passport;
    }

    /**
     * Whether the passport's scan file is on the disk.
     */
    public function exists(PassportEntry $passport): bool
    {
        return $passport->scan_path !== null && $this->disk()->exists($passport->scan_path);
    }

    public function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
