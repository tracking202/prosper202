<?php

declare(strict_types=1);

namespace Api\V3\Support;

use Api\V3\Exception\ValidationException;

/**
 * What a list request narrows and orders by: `filter[<field>]=<value>`
 * (a whole-number field takes several, `filter[aff_network_id]=1,4`), `q=`
 * (the record's name contains it, in any case), and `sort=` / `sort_dir=`
 * (one field of the list's own, ascending or descending).
 *
 * Every value is read strictly and anything the list cannot apply is a 422
 * naming the parameter: a narrowing or an order that is dropped answers a
 * different question than the one asked and reads as its answer (#4).
 */
final class ListQuery
{
    /** Values one multi-value filter may name; a longer list is refused, not cut. */
    public const int MAX_FILTER_VALUES = 100;

    /** Characters q may hold; no name the lists search is longer. */
    public const int MAX_SEARCH_LENGTH = 255;

    /** LIKE's escape character for a searchPattern(), the same under every sql_mode. */
    public const string ESCAPE = '!';

    private function __construct()
    {
    }

    /**
     * The list's `filter[<field>]=<value>` pairs, each a field of $fields
     * with values its type can hold. A whole-number field takes a comma list
     * (`1,4`), which matches a record holding any of them; any other field's
     * value is one value, commas and all, since a name may hold a comma.
     *
     * @param array<string, mixed> $params
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, non-empty-list<scalar>>
     */
    public static function filters(array $params, array $fields): array
    {
        if (!array_key_exists('filter', $params)) {
            return [];
        }
        $filters = $params['filter'];
        $filterable = 'Filter by field: ' . implode(', ', array_keys($fields));
        if (!is_array($filters)) {
            throw new ValidationException('Invalid filter', [
                'filter' => 'Use filter[<field>]=<value>. ' . $filterable,
            ]);
        }
        $errors = [];
        $read = [];
        foreach ($filters as $field => $value) {
            $field = (string) $field;
            $key = 'filter[' . $field . ']';
            if (!isset($fields[$field])) {
                $errors[$key] = 'Not a field of this list. ' . $filterable;
                continue;
            }
            $type = $fields[$field]['type'] ?? 's';
            if (!is_scalar($value)) {
                $errors[$key] = 'Must be a single value'
                    . ($type === 'i' ? ', or whole numbers separated by commas (1,4)' : '');
            } elseif ($type === 'i') {
                $values = self::wholeNumbers((string) $value);
                if ($values === null) {
                    $errors[$key] = 'Must be a whole number, or whole numbers separated by commas (1,4)';
                } elseif (count($values) > self::MAX_FILTER_VALUES) {
                    $errors[$key] = 'At most ' . self::MAX_FILTER_VALUES . ' values';
                } else {
                    $read[$field] = $values;
                }
            } elseif ($type === 'd' && !is_numeric($value)) {
                $errors[$key] = 'Must be a number';
            } elseif (($fields[$field]['format'] ?? null) === 'date' && !CalendarDate::isValid((string) $value)) {
                // A date column compared with 'garbage' matches nothing, and
                // the empty list read as "no events that day".
                $errors[$key] = 'Must be a date written YYYY-MM-DD';
            } else {
                $read[$field] = [$value];
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Invalid filter', $errors);
        }

        return $read;
    }

    /**
     * Whole numbers separated by single commas, or null: no spaces, no empty
     * item (`1,,4`, `1,`), since a stray comma is a value the caller lost.
     *
     * @return non-empty-list<string>|null
     */
    private static function wholeNumbers(string $text): ?array
    {
        if (preg_match('/^-?[0-9]+(,-?[0-9]+)*$/D', $text) !== 1) {
            return null;
        }

        return explode(',', $text);
    }

    /**
     * q as a LIKE pattern that matches a value containing it, in any case,
     * with LIKE's own wildcards taken literally; null when there is no q
     * (absent or '').
     *
     * @param array<string, mixed> $params
     */
    public static function searchPattern(array $params): ?string
    {
        if (!array_key_exists('q', $params) || $params['q'] === '' || $params['q'] === null) {
            return null;
        }
        $q = $params['q'];
        if (!is_string($q)) {
            throw new ValidationException('Invalid q', ['q' => 'Must be one piece of text']);
        }
        if (!mb_check_encoding($q, 'UTF-8')) {
            throw new ValidationException('Invalid q', ['q' => 'Must be UTF-8 text']);
        }
        if (mb_strlen($q, 'UTF-8') > self::MAX_SEARCH_LENGTH) {
            throw new ValidationException('Invalid q', ['q' => 'At most ' . self::MAX_SEARCH_LENGTH . ' characters']);
        }
        $escaped = strtr(mb_strtolower($q, 'UTF-8'), [
            self::ESCAPE => self::ESCAPE . self::ESCAPE,
            '%' => self::ESCAPE . '%',
            '_' => self::ESCAPE . '_',
        ]);

        return '%' . $escaped . '%';
    }

    /** The refusal of q on a list that has no text to search. */
    public static function notSearchable(): ValidationException
    {
        return new ValidationException('Invalid q', [
            'q' => 'This list cannot be searched by text; filter it with filter[<field>]=<value>',
        ]);
    }

    /**
     * The condition that $column holds one of a filter's values.
     *
     * @param non-empty-list<scalar> $values ListQuery::filters()' values for it
     * @return array{string, list<scalar>, string} [sql, binds, types]
     */
    public static function anyOf(string $column, array $values, string $type): array
    {
        $sql = count($values) === 1
            ? "$column = ?"
            : "$column IN (" . implode(', ', array_fill(0, count($values), '?')) . ')';

        return [$sql, $values, str_repeat($type, count($values))];
    }

    /**
     * The condition that one of $columns contains the search: LOWER() on
     * both sides, so it holds whatever the column's collation.
     *
     * @param non-empty-list<string> $columns SQL column expressions
     * @return array{string, list<string>, string} [sql, binds, types]
     */
    public static function searchCondition(array $columns, string $pattern): array
    {
        $terms = [];
        $binds = [];
        foreach ($columns as $column) {
            $terms[] = 'LOWER(' . $column . ") LIKE ? ESCAPE '" . self::ESCAPE . "'";
            $binds[] = $pattern;
        }

        return ['(' . implode(' OR ', $terms) . ')', $binds, str_repeat('s', count($binds))];
    }

    /**
     * The ORDER BY a `sort=` / `sort_dir=` pair names, ending with the
     * primary key in the same direction so a page break between two equal
     * values neither repeats nor skips a row; null when there is no sort,
     * so the list keeps its own order. sort_dir without sort is refused:
     * the direction of the list's own order is not the caller's to flip.
     *
     * @param array<string, mixed> $params
     * @param array<string, string> $sortable the sort name => its SQL column
     */
    public static function orderBy(array $params, array $sortable, string $primaryKey): ?string
    {
        $sort = $params['sort'] ?? '';
        $dir = $params['sort_dir'] ?? '';
        $valid = 'Valid values: ' . implode(', ', array_keys($sortable));
        if ($sort === '' || $sort === null) {
            if ($dir !== '' && $dir !== null) {
                throw new ValidationException('Invalid sort_dir', [
                    'sort_dir' => 'Name a field with sort= to sort by. ' . $valid,
                ]);
            }

            return null;
        }
        if (!is_string($sort) || !array_key_exists($sort, $sortable)) {
            throw new ValidationException('Invalid sort field', [
                'sort' => $sortable === [] ? 'This list cannot be sorted' : $valid,
            ]);
        }
        $direction = 'ASC';
        if ($dir !== '' && $dir !== null) {
            $direction = is_string($dir) ? strtoupper($dir) : '';
            if ($direction !== 'ASC' && $direction !== 'DESC') {
                throw new ValidationException('Invalid sort_dir', ['sort_dir' => 'Valid values: asc, desc']);
            }
        }
        $column = $sortable[$sort];

        return $column === $primaryKey
            ? "$column $direction"
            : "$column $direction, $primaryKey $direction";
    }
}
