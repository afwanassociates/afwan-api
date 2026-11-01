<?php

namespace App\Enums;

/**
 * Which line of the company's business a party's money is for. Recruitment
 * is the default and the only one with extra details (the destination
 * country); the others are plain options for now.
 */
enum BusinessType: string
{
    case Recruitment = 'recruitment';
    case Land = 'land';
    case Solar = 'solar';
    case Railway = 'railway';

    public function label(): string
    {
        return match ($this) {
            self::Recruitment => 'Recruitment',
            self::Land => 'Land',
            self::Solar => 'Solar',
            self::Railway => 'Railway',
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
