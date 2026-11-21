<?php

namespace App\Enums;

/**
 * Side of a party's opening balance: receivable (the party owes us, debit)
 * or payable (we owe the party, credit).
 */
enum BalanceType: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';

    public function label(): string
    {
        return match ($this) {
            self::Receivable => 'Receivable (Dr)',
            self::Payable => 'Payable (Cr)',
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
