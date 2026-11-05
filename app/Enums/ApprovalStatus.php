<?php

namespace App\Enums;

/**
 * Approval of an accounts entry (Prepared → Approved). Only an approved entry
 * counts in the cash book, cash in hand and the party ledgers. A returned
 * (rejected) entry goes back to its preparer, and saving it again makes it
 * pending.
 */
enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending approval',
            self::Approved => 'Approved',
            self::Rejected => 'Returned',
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
