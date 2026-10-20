<?php

namespace App\Enums;

/**
 * The mark a fit passport needs before it reaches Calling / Work Permit.
 * Online is the only mark; null on the passport means not marked.
 */
enum EntryMode: string
{
    case Online = 'online';

    /**
     * Human readable name of the mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
        };
    }

    /**
     * All mode values as strings.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
