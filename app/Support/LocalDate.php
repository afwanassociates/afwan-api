<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Calendar dates in the business timezone (config medical.timezone, Asia/Dhaka).
 * The app clock runs in UTC, so between 00:00 and 06:00 in Dhaka "today" here
 * is one day ahead of UTC's today.
 */
class LocalDate
{
    /**
     * Today's date at 00:00 in the business timezone.
     */
    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /**
     * A Y-m-d date (or a date cast) as midnight in the business timezone.
     */
    public static function parse(string|\DateTimeInterface $date): CarbonImmutable
    {
        $value = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, self::timezone());
    }

    public static function timezone(): string
    {
        return (string) config('medical.timezone', 'Asia/Dhaka');
    }
}
