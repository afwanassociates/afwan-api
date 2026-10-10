<?php

namespace App\Models;

use App\Enums\StepStatus;
use Database\Factories\PassportStepRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One record of a workflow step after medical (calling, visa, bmet, flight).
 * Create, change and delete records only through App\Services\WorkflowService:
 * it checks the step order and keeps the passport's stage in step.
 */
#[Fillable(['status', 'reference_no', 'step_date', 'valid_until', 'details', 'remarks'])]
class PassportStepRecord extends Model
{
    /** @use HasFactory<PassportStepRecordFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
            'step_date' => 'date',
            'valid_until' => 'date',
            'details' => 'array',
        ];
    }

    /**
     * A value from the step-specific details, e.g. the flight's departure_date.
     */
    public function detail(string $key): mixed
    {
        return $this->details[$key] ?? null;
    }

    /**
     * @return BelongsTo<PassportEntry, $this>
     */
    public function passportEntry(): BelongsTo
    {
        return $this->belongsTo(PassportEntry::class);
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
