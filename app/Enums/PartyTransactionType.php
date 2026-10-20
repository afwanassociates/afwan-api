<?php

namespace App\Enums;

/**
 * A line of a party ledger. Receivable, refund, loan repayment and drawing are
 * debits (the party owes us more, or we owe them less); payment, loan and
 * capital are credits. Receivable may be entered by hand on the ledger until
 * the Sales module exists; the others come from cash receipts and cash
 * payments (a refund is a cash payment, so it is never entered twice).
 */
enum PartyTransactionType: string
{
    case Receivable = 'receivable';
    case Payment = 'payment';
    case Refund = 'refund';
    case Loan = 'loan';
    case Capital = 'capital';
    case LoanRepayment = 'loan_repayment';
    case Drawing = 'drawing';

    public function label(): string
    {
        return match ($this) {
            self::Receivable => 'Receivable',
            self::Payment => 'Payment received',
            self::Refund => 'Refund',
            self::Loan => 'Loan received',
            self::Capital => 'Capital received',
            self::LoanRepayment => 'Loan repaid',
            self::Drawing => 'Owner drawing',
        };
    }

    public function isDebit(): bool
    {
        return in_array($this, [self::Receivable, self::Refund, self::LoanRepayment, self::Drawing], true);
    }

    /**
     * Types that may be entered by hand on the ledger.
     *
     * @return list<string>
     */
    public static function manualValues(): array
    {
        return [self::Receivable->value];
    }
}
