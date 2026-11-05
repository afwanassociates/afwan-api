<?php

namespace App\Enums;

/**
 * Bangladesh mobile financial services (MFS) a receipt can come through.
 */
enum MobileBankingProvider: string
{
    case Bkash = 'bkash';
    case Nagad = 'nagad';
    case Rocket = 'rocket';
    case Upay = 'upay';
    case Mcash = 'mcash';
    case Tap = 'tap';
    case OkWallet = 'ok_wallet';
    case SureCash = 'surecash';
    case MyCash = 'mycash';
    case FirstCash = 'firstcash';
    case IslamicWallet = 'islamic_wallet';
    case MeghnaPay = 'meghna_pay';

    public function label(): string
    {
        return match ($this) {
            self::Bkash => 'bKash',
            self::Nagad => 'Nagad',
            self::Rocket => 'Rocket',
            self::Upay => 'Upay',
            self::Mcash => 'mCash',
            self::Tap => 'Tap',
            self::OkWallet => 'OK Wallet',
            self::SureCash => 'SureCash',
            self::MyCash => 'MYCash',
            self::FirstCash => 'FirstCash',
            self::IslamicWallet => 'Islamic Wallet',
            self::MeghnaPay => 'Meghna Pay',
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
