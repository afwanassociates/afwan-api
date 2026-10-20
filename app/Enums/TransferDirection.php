<?php

namespace App\Enums;

/**
 * A cash transfer: cash in hand deposited to a bank (cash goes down) or
 * withdrawn from a bank into cash (cash goes up).
 */
enum TransferDirection: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Cash deposited to bank',
            self::Withdrawal => 'Cash withdrawn from bank',
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
