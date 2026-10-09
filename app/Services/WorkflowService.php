<?php

namespace App\Services;

use App\Models\MedicalRecord;
use App\Models\PassportEntry;
use Illuminate\Support\Collection;

/**
 * Keeps a passport's stored workflow position in step with its records. The
 * only place that sets passport_entries.current_medical_id and
 * stage_status_date. Both depend only on records, never on today's date.
 */
class WorkflowService
{
    /**
     * Point current_medical_id at the latest non-deleted medical record (by
     * medical_date, ties by id) and set stage_status_date to the date of the
     * event behind the current status: the received date while the medical is
     * pending, otherwise the current medical's date (unfit, or fit and
     * waiting for the next step).
     */
    public function recompute(PassportEntry $passport): PassportEntry
    {
        $current = MedicalRecord::query()
            ->where('passport_entry_id', $passport->id)
            ->orderByDesc('medical_date')
            ->orderByDesc('id')
            ->first(['id', 'medical_date']);

        $values = [
            'current_medical_id' => $current?->id,
            'stage_status_date' => ($current?->medical_date ?? $passport->passport_received_date)?->toDateString(),
        ];

        $changed = array_filter(
            $values,
            fn (int|string|null $value, string $column) => $this->stored($passport, $column) !== ($value === null ? null : (string) $value),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changed !== []) {
            // Query builder update: a new medical is not an edit of the passport,
            // so its updated_at and updated_by stay as they are.
            PassportEntry::withTrashed()->whereKey($passport->id)->toBase()->update($changed);
        }

        foreach ($values as $column => $value) {
            $passport->setAttribute($column, $value)->syncOriginalAttribute($column);
        }
        $passport->unsetRelation('currentMedical');

        return $passport;
    }

    /**
     * Recompute every passport, soft-deleted ones included, in chunks (used to
     * backfill after a migration).
     */
    public function recomputeAll(int $chunk = 500): int
    {
        $count = 0;

        PassportEntry::withTrashed()
            ->select(['id', 'passport_received_date', 'current_medical_id', 'stage_status_date'])
            ->chunkById($chunk, function (Collection $passports) use (&$count) {
                foreach ($passports as $passport) {
                    $this->recompute($passport);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * The column's stored value as a string, for comparison.
     */
    protected function stored(PassportEntry $passport, string $column): ?string
    {
        $value = $passport->getAttribute($column);

        return match (true) {
            $value === null => null,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };
    }
}
