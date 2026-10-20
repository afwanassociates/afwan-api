<?php

namespace App\Enums;

/**
 * What received money is: a normal cash receipt or a loan we must pay back.
 */
enum CashReceiptType: string
{
    case Cash = 'cash';
    case Loan = 'loan';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Loan => 'Loan',
        };
    }

    /**
     * The ledger line a receipt of this type writes for the party: cash from a
     * client is a payment against their receivable, cash from an owner is
     * capital, and a loan from either is a loan.
     */
    public function ledgerType(PartyType $party): PartyTransactionType
    {
        return match (true) {
            $this === self::Loan => PartyTransactionType::Loan,
            $party === PartyType::Owner => PartyTransactionType::Capital,
            default => PartyTransactionType::Payment,
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
