<?php

namespace App\Enums;

/**
 * A passport's assignment to a recruitment agent: data entry assigns (pending), the
 * operations head approves it or returns it for correction. A returned one stays
 * in the history; data entry sends a corrected assignment as a new one.
 */
enum AgentAssignmentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Returned => 'Returned for correction',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
