<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * One action on a passport (who, what, when), for the passport history. Only
 * ever added; see App\Support\PassportHistory.
 *
 * details: list of {label, value, type} (type: text or date), like the history's.
 */
class PassportActivity extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Record an action, by $by or the logged-in user.
     *
     * @param  list<array{label: string, value: mixed, type?: string}>  $details
     */
    public static function record(PassportEntry|int $passport, string $step, string $action, string $title, array $details = [], ?User $by = null, ?string $remarks = null): self
    {
        $details = array_values(array_filter(
            array_map(fn (array $d) => [...$d, 'type' => $d['type'] ?? 'text'], $details),
            fn (array $d) => $d['value'] !== null && $d['value'] !== '',
        ));

        return self::query()->create([
            'passport_entry_id' => $passport instanceof PassportEntry ? $passport->id : $passport,
            'step_key' => $step,
            'action' => $action,
            'title' => $title,
            'details' => $details === [] ? null : $details,
            'remarks' => $remarks,
            'user_id' => $by?->id ?? Auth::id(),
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
