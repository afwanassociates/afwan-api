<?php

namespace App\Enums;

/**
 * What kind of client a party is: an agency (with an RL number) or a person.
 * Owners have no kind. Passports name the party who gave them as their
 * reference (passport_entries.reference_id).
 */
enum PartyKind: string
{
    case Person = 'person';
    case Agency = 'agency';

    /**
     * Human readable name of the kind.
     */
    public function label(): string
    {
        return match ($this) {
            self::Person => 'Person',
            self::Agency => 'Agency',
        };
    }

    /**
     * All kind values as strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
