<?php

namespace App\Enums;

/**
 * The outcome of a medical test.
 */
enum MedicalResult: string
{
    case Fit = 'fit';
    case Unfit = 'unfit';

    /**
     * Human readable name of the result.
     */
    public function label(): string
    {
        return match ($this) {
            self::Fit => 'Fit',
            self::Unfit => 'Unfit',
        };
    }
}
