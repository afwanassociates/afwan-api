<?php

namespace App\Enums;

/**
 * What received money is: cash (from a party, a payment against what they
 * owe; from an owner, capital) or an owner returning money the company lent
 * them (loan return, naming the loan).
 */
enum CashReceiptType: string
{
    case Cash = 'cash';
    case LoanReturn = 'loan_return';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::LoanReturn => 'Loan return',
        };
    }

    /**
     * The ledger line a receipt of this type writes for the party: cash from a
     * client is a payment against their receivable, cash from an owner is
     * capital, and an owner's loan return settles their loan.
     */
    public function ledgerType(PartyType $party): PartyTransactionType
    {
        return match (true) {
            $this === self::LoanReturn => PartyTransactionType::LoanReturn,
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
