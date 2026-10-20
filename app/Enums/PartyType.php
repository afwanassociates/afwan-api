<?php

namespace App\Enums;

/**
 * Who a party is. Clients and owners share one table and one ledger, but each
 * type has its own ID series (PT-0001, OW-001), and reports such as the
 * receivable list count clients only.
 */
enum PartyType: string
{
    case Client = 'client';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Party / Client',
            self::Owner => 'Owner',
        };
    }

    /**
     * Prefix of the party ID.
     */
    public function codePrefix(): string
    {
        return match ($this) {
            self::Client => 'PT',
            self::Owner => 'OW',
        };
    }

    /**
     * The party ID for the given sequence number: PT-0001, OW-001.
     */
    public function code(int $sequence): string
    {
        $digits = match ($this) {
            self::Client => 4,
            self::Owner => 3,
        };

        return $this->codePrefix().'-'.str_pad((string) $sequence, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
