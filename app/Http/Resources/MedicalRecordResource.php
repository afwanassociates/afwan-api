<?php

namespace App\Http\Resources;

use App\Models\MedicalRecord;
use App\Support\LocalDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MedicalRecord
 */
class MedicalRecordResource extends JsonResource
{
    /**
     * Relations to eager load before returning records.
     *
     * @var list<string>
     */
    public const RELATIONS = ['creator', 'medicalCenter'];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...self::summary($this->resource),
            'passport_entry_id' => $this->passport_entry_id,
            'result_label' => $this->result->label(),
            'updated_by' => $this->updated_by,
            'updated_at' => $this->updated_at,
            // What the current user may do with this record, for the SPA's buttons.
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
        ];
    }

    /**
     * The fields shown wherever a medical record appears, including as a
     * passport's current_medical (load creator and medicalCenter first).
     *
     * @return array<string, mixed>
     */
    public static function summary(MedicalRecord $record): array
    {
        return [
            'id' => $record->id,
            'medical_date' => $record->medical_date->toDateString(),
            'result' => $record->result->value,
            'valid_until' => $record->valid_until?->toDateString(),
            'days_left' => self::daysLeft($record),
            'remarks' => $record->remarks,
            'medical_center' => $record->medicalCenter ? [
                'id' => $record->medicalCenter->id,
                'name' => $record->medicalCenter->name,
            ] : null,
            'slip_no' => $record->slip_no,
            'slip_date' => $record->slip_date?->toDateString(),
            'recorded_by' => $record->creator ? [
                'id' => $record->creator->id,
                'name' => $record->creator->name,
            ] : null,
            'created_at' => $record->created_at,
        ];
    }

    /**
     * Days from today (Dhaka) until valid_until; negative once expired, null
     * for unfit results.
     */
    public static function daysLeft(MedicalRecord $record): ?int
    {
        if ($record->valid_until === null) {
            return null;
        }

        return (int) LocalDate::today()->diffInDays(LocalDate::parse($record->valid_until), false);
    }
}
