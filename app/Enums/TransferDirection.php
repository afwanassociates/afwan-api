<?php

namespace App\Enums;

/**
 * A deposit / withdrawal: cash in hand deposited to a bank or a mobile banking
 * wallet (cash goes down), or withdrawn from one into cash (cash goes up).
 */
enum TransferDirection: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Cash deposited to bank',
            self::Withdrawal => 'Cash withdrawn from bank',
        };
    }

    /**
     * The label for a mobile banking wallet ("Cash in to mobile banking") or a bank.
     */
    public function labelFor(PaymentMethod $channel): string
    {
        if ($channel !== PaymentMethod::MobileBanking) {
            return $this->label();
        }

        return match ($this) {
            self::Deposit => 'Cash in to mobile banking',
            self::Withdrawal => 'Cash out from mobile banking',
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
