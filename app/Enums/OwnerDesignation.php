<?php

namespace App\Enums;

/**
 * The position an owner holds in the company. Clients have none.
 */
enum OwnerDesignation: string
{
    case Chairman = 'chairman';
    case ManagingDirector = 'managing_director';
    case Director = 'director';

    public function label(): string
    {
        return match ($this) {
            self::Chairman => 'Chairman',
            self::ManagingDirector => 'Managing Director',
            self::Director => 'Director',
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
