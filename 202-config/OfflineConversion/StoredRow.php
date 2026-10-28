<?php

declare(strict_types=1);

namespace Prosper202\OfflineConversion;

/**
 * A column of a row this namespace reads, as the type it must be. A value
 * of another shape is a row nothing here wrote or a read that went wrong,
 * and is refused naming the column rather than cast into a number or a
 * word it never was.
 */
final class StoredRow
{
    private function __construct()
    {
    }

    /** @param array<array-key, mixed> $row */
    public static function int(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A-?[0-9]{1,18}\z/D', $value) === 1) {
            return (int) $value;
        }
        throw new \UnexpectedValueException($column . ' read as ' . get_debug_type($value) . ', not a whole number');
    }

    /** @param array<array-key, mixed> $row */
    public static function nullableInt(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : self::int($row, $column);
    }

    /** @param array<array-key, mixed> $row */
    public static function text(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        throw new \UnexpectedValueException($column . ' read as ' . get_debug_type($value) . ', not text');
    }

    /** @param array<array-key, mixed> $row */
    public static function nullableText(array $row, string $column): ?string
    {
        return ($row[$column] ?? null) === null ? null : self::text($row, $column);
    }
}
