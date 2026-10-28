<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

/**
 * The LTV reads' numbers as JSON numbers. mysqli hands a DECIMAL column, a
 * SUM() or an AVG() back as a string ("54.12500", "2") beside integer
 * columns that are numbers, so one field read as a number from one endpoint
 * and as text from another. A NULL stays null, and a value the server already
 * answered as a number is left as it is: which expressions come back as text
 * differs between MariaDB and MySQL, so every such column is named, not only
 * the ones one server answers as text.
 */
final class LtvNumbers
{
    /**
     * Money and measures (a quantity, an average, a rate): numbers.
     *
     * @param array<string, mixed> $row
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    public static function decimals(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (isset($row[$column]) && is_string($row[$column]) && is_numeric($row[$column])) {
                $row[$column] = (float) $row[$column];
            }
        }

        return $row;
    }

    /**
     * Counts and unix times: integers. A value with a fraction is left as the
     * server gave it, never cut, so the type tests see it.
     *
     * @param array<string, mixed> $row
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    public static function integers(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (isset($row[$column]) && is_string($row[$column]) && preg_match('/^-?\d+$/D', $row[$column]) === 1) {
                $row[$column] = (int) $row[$column];
            }
        }

        return $row;
    }
}
