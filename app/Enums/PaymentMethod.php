<?php

namespace App\Enums;

/**
 * How money arrived: cash in hand, or a bank deposit (with bank name and branch).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash in hand',
            self::Bank => 'Bank',
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
