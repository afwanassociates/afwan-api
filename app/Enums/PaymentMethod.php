<?php

namespace App\Enums;

/**
 * How money arrived: cash in hand, a bank deposit (with bank name and branch),
 * or mobile banking (with the provider and the wallet number).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case MobileBanking = 'mobile_banking';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash in hand',
            self::Bank => 'Bank',
            self::MobileBanking => 'Mobile banking',
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
