<?php

namespace App\Models;

use App\Enums\MedicalResult;
use Database\Factories\MedicalRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One medical test result for a passport. Create, change and delete records
 * only through App\Services\MedicalService: it sets valid_until and the
 * tracking columns, and keeps the passport's current_medical_id in step.
 */
#[Fillable(['medical_date', 'result', 'remarks', 'medical_center_id', 'slip_no', 'slip_date'])]
class MedicalRecord extends Model
{
    /** @use HasFactory<MedicalRecordFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'medical_date' => 'date',
            'result' => MedicalResult::class,
            'valid_until' => 'date',
            'slip_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PassportEntry, $this>
     */
    public function passportEntry(): BelongsTo
    {
        return $this->belongsTo(PassportEntry::class);
    }

    /**
     * Includes soft-deleted centres, so old medicals still show theirs.
     *
     * @return BelongsTo<MedicalCenter, $this>
     */
    public function medicalCenter(): BelongsTo
    {
        return $this->belongsTo(MedicalCenter::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
