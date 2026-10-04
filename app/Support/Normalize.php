<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Input normalization shared by the model mutators and the form requests,
 * so validation (e.g. uniqueness) sees the same value that is stored.
 */
class Normalize
{
    /**
     * Trim and remove all whitespace, then uppercase: " ab 12 345 " becomes "AB12345".
     */
    public static function passportNumber(?string $value): ?string
    {
        return $value === null ? null : Str::upper((string) preg_replace('/\s+/u', '', $value));
    }

    /**
     * Trim, collapse repeated spaces, then uppercase: " md  rahim " becomes "MD RAHIM".
     */
    public static function passportName(?string $value): ?string
    {
        return $value === null ? null : Str::upper(Str::squish($value));
    }

    /**
     * Trim and collapse repeated spaces, keeping the case as typed.
     */
    public static function name(?string $value): ?string
    {
        return $value === null ? null : Str::squish($value);
    }
}
