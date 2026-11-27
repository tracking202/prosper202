<?php

declare(strict_types=1);

namespace Tests\Attribution\Support;

use Tests\Support\AssembledStrings;

/**
 * The statements in a PHP source that write a table's `status` column, and
 * the quoted values a statement compares it with, read from the strings PHP
 * assembles (Tests\Support\AssembledStrings): what
 * StatusColumnsAreWrittenOnlyByTheirOwnersTest asks of every file.
 *
 * A statement is read where a string starts with it (after any SQL
 * comments). An UPDATE writes the column when its SET assigns it; an INSERT
 * or REPLACE when its column list names it, when it has no column list, or
 * when its ON DUPLICATE KEY UPDATE assigns it. A table the scan cannot read
 * (a variable, a call) is UNREAD_TABLE, so a write built at runtime shows
 * that it is rather than reading as a write to some other table (CLAUDE.md
 * #20). An UPDATE whose SET names a column the scan cannot read (a variable
 * or a %s where a column goes, or a SET that runs on past the string) may
 * write the status: it is a write with `unread` holding what stands where
 * the column goes (the operand's source, `%s`, or '' for what follows the
 * string), never no write.
 *
 * An UPDATE is guarded when its own WHERE compares the status; one inside a
 * subquery of the WHERE is about some other row and is not a guard.
 *
 * @phpstan-type Write array{line: int, kind: 'insert'|'update', tables: list<string>, guarded: bool, unread: list<string>, text: string}
 */
final class StatusWrites
{
    public const UNREAD_TABLE = '?';

    /** SQL comments a statement may start with. */
    private const LEAD = '(?:\s|\/\*.*?\*\/|(?:--\s|#)[^\n]*(?:\n|\z))*';

    private const UPDATE = '/\A' . self::LEAD . 'UPDATE\b(?:\s+(?:LOW_PRIORITY|IGNORE))*\s+(.+?)\s+SET\b(.*)\z/is';

    private const INSERT = '/\A' . self::LEAD . '(?:INSERT|REPLACE)\b(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*\s+(?:INTO\s+)?((?:`?[\w\x00]+`?\.)?`?[\w\x00%$]+`?)\s*(\([^)]*\))?(.*)\z/is';

    /** Each table a reference list names: the first, and each after JOIN or a comma. */
    private const TABLE_REF = '/(?:\A|\b(?:STRAIGHT_)?JOIN\b|,)\s*\(?\s*(?:`?[\w\x00]+`?\.)?`?((?:[\w\x00]|%(?:\d+\$)?s)+)/i';

    /** A part the scan cannot read where an assignment's column goes, or a SET that runs on past the string. */
    private const UNREAD_COLUMN = '/(?:\A|,)\s*(?:`?\w+`?\s*\.\s*)?`?(?:\x00|%(?:\d+\$)?s)|(?:\x00|%(?:\d+\$)?s)\s*\z/';

    /** The column, bare, backticked or under an alias, and not a longer name (status_reason). */
    private const COLUMN = '(?<![\w`.])(?:`?\w+`?\s*\.\s*)?`?status`?(?![\w`])';

    /**
     * @return list<Write>
     */
    public static function of(string $source): array
    {
        $out = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text, 'holes' => $holes]) {
            if (preg_match(self::UPDATE, $text, $m) === 1) {
                $where = preg_split('/\bWHERE\b/i', $m[2], 2) ?: [$m[2]];
                $assigns = preg_match('/' . self::COLUMN . '\s*=(?!=)/i', $where[0]) === 1;
                // With a WHERE the SET is whole; without one, what follows the string may add to it.
                $unread = $assigns ? [] : self::unreadColumns(isset($where[1]) ? $where[0] . ';' : $where[0], strlen($text) - strlen($m[2]), $text, $holes);
                if ($assigns || $unread !== []) {
                    $out[] = [
                        'line' => $line, 'kind' => 'update', 'tables' => self::tables($m[1]),
                        'guarded' => preg_match('/' . self::COLUMN . '\s*(?:=|IN\b)/i', self::withoutSubqueries($where[1] ?? '')) === 1,
                        'unread' => $unread,
                        'text' => self::shown($text),
                    ];
                }
            } elseif (preg_match(self::INSERT, $text, $m) === 1) {
                $columns = $m[2] ?? '';
                $rest = $m[3] ?? '';
                $onDuplicate = preg_split('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $rest, 2)[1] ?? '';
                // INSERT … SET col = … has no column list either; it is read by its assignments.
                $bySet = $columns === '' && preg_match('/\A\s*SET\b/i', $rest) === 1;
                $writes = $bySet
                    ? preg_match('/' . self::COLUMN . '\s*=(?!=)/i', $rest) === 1
                    : ($columns === '' || preg_match('/' . self::COLUMN . '/i', $columns) === 1
                        || preg_match('/' . self::COLUMN . '\s*=(?!=)/i', $onDuplicate) === 1);
                if ($writes) {
                    $out[] = ['line' => $line, 'kind' => 'insert', 'tables' => self::tables($m[1]), 'guarded' => false, 'unread' => [], 'text' => self::shown($text)];
                }
            }
        }

        return $out;
    }

    /**
     * The quoted values a statement naming $table compares its status with
     * or assigns to it, by line. A value the scan cannot read (an
     * interpolated enum case) is not one.
     *
     * @return list<array{line: int, value: string}>
     */
    public static function literals(string $source, string $table): array
    {
        $out = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            if (preg_match('/(?<![\w])' . preg_quote($table, '/') . '(?![\w])/i', $text) !== 1) {
                continue;
            }
            preg_match_all('/' . self::COLUMN . '\s*(?:=|<>|!=|<=>)\s*([\'"])([^\'"]*)\1/i', $text, $compared);
            $values = $compared[2];
            preg_match_all('/' . self::COLUMN . '\s+(?:NOT\s+)?IN\s*\(([^)]*)\)/i', $text, $lists);
            foreach ($lists[1] as $list) {
                preg_match_all('/([\'"])([^\'"]*)\1/', $list, $items);
                array_push($values, ...$items[2]);
            }
            foreach ($values as $value) {
                if (!str_contains($value, AssembledStrings::UNREAD)) {
                    $out[] = ['line' => $line, 'value' => $value];
                }
            }
        }

        return $out;
    }

    /**
     * What stands in $set where the scan cannot read a column: each hole's source, in order.
     *
     * @param list<string> $holes
     * @return list<string>
     */
    private static function unreadColumns(string $set, int $at, string $text, array $holes): array
    {
        preg_match_all(self::UNREAD_COLUMN, $set, $found, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($found[0] as [$match, $offset]) {
            $nul = strpos($match, AssembledStrings::UNREAD);
            $out[] = $nul === false ? '%s' : ($holes[substr_count($text, AssembledStrings::UNREAD, 0, $at + $offset + $nul)] ?? '');
        }

        return $out;
    }

    /** A WHERE with each subquery removed and every other parenthesis emptied, innermost first. */
    private static function withoutSubqueries(string $where): string
    {
        do {
            $where = (string) preg_replace_callback(
                '/\(([^()]*)\)/',
                static fn (array $m): string => preg_match('/\A\s*SELECT\b/i', $m[1]) === 1 ? ' ' : ' [] ',
                $where,
                -1,
                $count
            );
        } while ($count > 0);

        return $where;
    }

    /** @return list<string> */
    private static function tables(string $references): array
    {
        preg_match_all(self::TABLE_REF, $references, $m);
        $tables = [];
        foreach ($m[1] as $table) {
            $tables[] = strpbrk($table, AssembledStrings::UNREAD . '%$') !== false ? self::UNREAD_TABLE : strtolower($table);
        }

        return array_values(array_unique($tables));
    }

    private static function shown(string $statement): string
    {
        $folded = trim((string) preg_replace('/\s+/', ' ', $statement));

        return str_replace(AssembledStrings::UNREAD, '…', strlen($folded) > 90 ? substr($folded, 0, 90) : $folded);
    }
}
