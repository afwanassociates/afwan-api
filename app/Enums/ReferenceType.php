<?php

namespace App\Enums;

/**
 * Who referred a passport: a person or an agency.
 */
enum ReferenceType: string
{
    case Person = 'person';
    case Agency = 'agency';

    /**
     * Human readable name of the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Person => 'Person',
            self::Agency => 'Agency',
        };
    }

    /**
     * All type values as strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
