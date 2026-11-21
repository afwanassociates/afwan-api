<?php

namespace App\Support;

/**
 * Build "contains" patterns for LIKE queries from user input.
 */
class Like
{
    /**
     * Escape LIKE wildcards so "%" and "_" in the search match literally.
     */
    public static function contains(string $value): string
    {
        return '%'.addcslashes($value, '\\%_').'%';
    }
}
