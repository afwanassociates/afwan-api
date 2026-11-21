<?php

namespace App\Enums;

/**
 * What an expense (a cash payment to an "other" payee) was spent on.
 */
enum ExpenseCategory: string
{
    case OfficeRent = 'office_rent';
    case UtilityBill = 'utility_bill';
    case InternetPhone = 'internet_phone';
    case Salary = 'salary';
    case TeaEntertainment = 'tea_entertainment';
    case Transport = 'transport';
    case Stationery = 'stationery';
    case Repair = 'repair';
    case GovernmentFee = 'government_fee';
    case Courier = 'courier';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OfficeRent => 'Office rent',
            self::UtilityBill => 'Utility bill (electricity, water, gas)',
            self::InternetPhone => 'Internet & mobile bill',
            self::Salary => 'Salary & wages',
            self::TeaEntertainment => 'Tea & entertainment',
            self::Transport => 'Transport & conveyance',
            self::Stationery => 'Stationery & printing',
            self::Repair => 'Repair & maintenance',
            self::GovernmentFee => 'Government & embassy fees',
            self::Courier => 'Courier & postage',
            self::Other => 'Other',
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
