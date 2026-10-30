<?php

namespace App\Enums;

/**
 * What a cash payment is. Refund, loan repayment and drawing write a debit
 * line in the payee's party ledger; an expense (other payee) writes none.
 */
enum CashPaymentType: string
{
    case Refund = 'refund';
    case LoanRepayment = 'loan_repayment';
    case Drawing = 'drawing';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Refund',
            self::LoanRepayment => 'Loan repayment',
            self::Drawing => 'Owner drawing',
            self::Expense => 'Expense',
        };
    }

    /**
     * The party-ledger line this payment writes, or null for an expense.
     */
    public function ledgerType(): ?PartyTransactionType
    {
        return match ($this) {
            self::Refund => PartyTransactionType::Refund,
            self::LoanRepayment => PartyTransactionType::LoanRepayment,
            self::Drawing => PartyTransactionType::Drawing,
            self::Expense => null,
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
