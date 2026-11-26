<?php

declare(strict_types=1);

namespace Api\V3\Support;

/**
 * Whether rows remain after one page of a limit/offset read: the read asks
 * for one row past the page, and that row's presence is the answer's
 * `truncated`. A page of exactly the rows that remain was otherwise
 * indistinguishable from a page with rows after it, and an agent summing one
 * page undercounted without knowing.
 *
 * Every v3 route that takes `offset` reads through both halves
 * (PagedAnswersSayWhetherRowsRemainTest): fetchLimit() is the LIMIT it binds
 * (or hands to the repository that binds it), cut() the rows it answers.
 */
final class PageRows
{
    private function __construct()
    {
    }

    /** The LIMIT to read a page of $limit rows with. */
    public static function fetchLimit(int $limit): int
    {
        if ($limit < 1) {
            throw new \LogicException('PageRows: a page holds at least one row');
        }

        return $limit + 1;
    }

    /**
     * The page's rows and whether rows remain after them, from rows read with
     * fetchLimit($limit).
     *
     * @template T
     * @param array<array-key, T> $rows
     * @return array{0: list<T>, 1: bool}
     */
    public static function cut(array $rows, int $limit): array
    {
        if ($limit < 1) {
            throw new \LogicException('PageRows: a page holds at least one row');
        }
        $rows = array_values($rows);
        if (count($rows) > $limit + 1) {
            // More than was asked for: the read did not bind fetchLimit().
            throw new \LogicException('PageRows: ' . count($rows) . ' rows for a page of ' . $limit . '; read them with fetchLimit()');
        }

        return [array_slice($rows, 0, $limit), count($rows) > $limit];
    }
}
