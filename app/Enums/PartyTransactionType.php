<?php

namespace App\Enums;

/**
 * A line of a party ledger. Receivable, refund and owner loan are debits (the
 * party or owner owes us more, or we owe them less); payment, capital, loan
 * return and loan bill are credits. Receivable may be entered by hand on the
 * ledger until the Sales module exists; the others come from cash receipts,
 * cash payments and owner loan bills (a refund is a cash payment, so it is
 * never entered twice).
 *
 * Owner loans: the company lends an owner money (owner loan, a cash payment);
 * the owner settles it by returning money (loan return, a cash receipt) or by
 * showing company bills they paid with it (loan bill).
 */
enum PartyTransactionType: string
{
    case Receivable = 'receivable';
    case Payment = 'payment';
    case Refund = 'refund';
    case Capital = 'capital';
    case OwnerLoan = 'owner_loan';
    case LoanReturn = 'loan_return';
    case LoanBill = 'loan_bill';

    public function label(): string
    {
        return match ($this) {
            self::Receivable => 'Receivable',
            self::Payment => 'Payment received',
            self::Refund => 'Payment refund',
            self::Capital => 'Capital received',
            self::OwnerLoan => 'Loan given to owner',
            self::LoanReturn => 'Loan returned',
            self::LoanBill => 'Loan settled by bill',
        };
    }

    public function isDebit(): bool
    {
        return in_array($this, [self::Receivable, self::Refund, self::OwnerLoan], true);
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
