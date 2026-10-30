<?php

namespace App\Exceptions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * A unique index rejected a save that had already passed validation, e.g. two
 * staff saving the same passport number at the same moment. MySQL's error text
 * contains the duplicate value, so it is never logged or returned.
 */
class DuplicateEntry
{
    /**
     * The field and message to return for each unique index.
     *
     * @var array<string, array{string, string}>
     */
    public const FIELDS = [
        'passport_entries_passport_number_unique' => ['passport_number', 'This passport number is already entered.'],
        'references_type_name_unique' => ['name', 'A reference with this name already exists.'],
        'companies_name_unique' => ['name', 'A company with this name already exists.'],
    ];

    /**
     * Log the violation without the duplicate value.
     */
    public static function report(UniqueConstraintViolationException $e): void
    {
        Log::warning('Unique constraint violation.', [
            'index' => self::index($e),
            'sql' => $e->getSql(),
        ]);
    }

    /**
     * A standard 422 validation error for known indexes, otherwise a 409.
     */
    public static function render(UniqueConstraintViolationException $e): JsonResponse
    {
        $known = self::FIELDS[self::index($e) ?? ''] ?? null;

        if ($known === null) {
            return response()->json(['message' => 'This record already exists.'], 409);
        }

        [$field, $message] = $known;

        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422);
    }

    /**
     * The name of the violated index, from MySQL's "for key 'table.index'" text.
     */
    public static function index(UniqueConstraintViolationException $e): ?string
    {
        preg_match("/for key '(?:[^'.]+\\.)?([^']+)'/", (string) ($e->errorInfo[2] ?? ''), $matches);

        return $matches[1] ?? null;
    }
}
