<?php

namespace App\Enums;

/**
 * Who a cash payment goes to: a party (client), an owner, or any other payee
 * typed by name (e.g. office rent) until the Expense module exists.
 */
enum PayeeType: string
{
    case Client = 'client';
    case Owner = 'owner';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Party / Client',
            self::Owner => 'Owner',
            self::Other => 'Other',
        };
    }

    /**
     * The party type of the payee, or null for other payees.
     */
    public function partyType(): ?PartyType
    {
        return match ($this) {
            self::Client => PartyType::Client,
            self::Owner => PartyType::Owner,
            self::Other => null,
        };
    }

    /**
     * The payment types allowed for this payee.
     *
     * @return list<CashPaymentType>
     */
    public function paymentTypes(): array
    {
        return match ($this) {
            self::Client => [CashPaymentType::Refund, CashPaymentType::LoanRepayment],
            self::Owner => [CashPaymentType::Drawing, CashPaymentType::LoanRepayment],
            self::Other => [CashPaymentType::Expense],
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
