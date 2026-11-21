<?php

namespace App\Enums;

/**
 * What a cash payment is. A refund (to a party) and an owner loan (money the
 * company lends an owner) write a debit line in the payee's ledger; an
 * expense (other payee) writes none.
 */
enum CashPaymentType: string
{
    case Refund = 'refund';
    case OwnerLoan = 'owner_loan';
    case Expense = 'expense';
    // A part payment to a recruitment agent (no ledger line, no passports).
    case AgentPayment = 'agent_payment';

    public function label(): string
    {
        return match ($this) {
            self::Refund => 'Refund',
            self::OwnerLoan => 'Owner loan',
            self::Expense => 'Expense',
            self::AgentPayment => 'Agent payment',
        };
    }

    /**
     * The party-ledger line this payment writes, or null for an expense.
     */
    public function ledgerType(): ?PartyTransactionType
    {
        return match ($this) {
            self::Refund => PartyTransactionType::Refund,
            self::OwnerLoan => PartyTransactionType::OwnerLoan,
            self::Expense, self::AgentPayment => null,
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
