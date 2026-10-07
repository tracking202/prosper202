<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Which SQL statements write a column that holds a request's address, read
 * from the strings as PHP assembles them (AssembledStrings): the table each
 * INSERT, REPLACE or UPDATE writes, and the address columns it names.
 *
 * An address column is one whose name has `ip` as a word (`ip`, `ip_id`,
 * `remote_ip`, `user_last_login_ip_id`, `user_pref_ip`), taken from the
 * installer's own table definitions, not from a list kept here. A write is
 * reported per table and column; a write whose columns the scan cannot read
 * — a column list or SET clause built at runtime, an INSERT with no column
 * list, a statement finished later with `.=` — is reported as `table.*`, and
 * one whose table it cannot read as `?.*`, never taken to write nothing
 * (CLAUDE.md #20).
 */
final class AddressWriteScan
{
    private const U = AssembledStrings::UNREAD;

    /** A table where a write names one: a 202_ name, backticked or not, or a part the scan cannot read. */
    private const TABLE = '(?:`?(202_\w+)`?|`?(\x00|%(?:\d+\$)?s)`?)';

    /** @var array<string, list<string>>|null table => its address columns */
    private static ?array $addressColumns = null;

    /**
     * Every table with an address column, and those columns.
     *
     * @return array<string, list<string>>
     */
    public static function addressColumns(): array
    {
        if (self::$addressColumns !== null) {
            return self::$addressColumns;
        }
        $out = [];
        foreach (glob(SourceScan::repoRoot() . '/202-config/Database/Tables/*Tables.php') ?: [] as $file) {
            $class = 'Prosper202\\Database\\Tables\\' . basename($file, '.php');
            foreach ($class::getDefinitions() as $definition) {
                foreach (preg_split('/\R/', $definition->createStatement) ?: [] as $line) {
                    if (preg_match('/^\s*`([^`]+)`\s+\w/', $line, $m) === 1 && self::isAddressColumn($m[1])) {
                        $out[$definition->tableName][] = $m[1];
                    }
                }
            }
        }
        ksort($out);

        return self::$addressColumns = $out;
    }

    public static function isAddressColumn(string $name): bool
    {
        return in_array('ip', explode('_', strtolower($name)), true);
    }

    /**
     * The address-column writes in one source, as "table.column" (column
     * `*` when the scan cannot read which columns, table `?` when it cannot
     * read which table) => the lines.
     *
     * @return array<string, list<int>>
     */
    public static function writes(string $source): array
    {
        $tables = self::addressColumns();
        $found = [];
        foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
            foreach (self::statements($text) as [$targets, $columns]) {
                foreach ($targets as $table) {
                    if ($table === '?') {
                        $found['?.*'][] = $line;
                        continue;
                    }
                    if (!isset($tables[$table])) {
                        continue;
                    }
                    if ($columns === null) {
                        $found[$table . '.*'][] = $line;
                        continue;
                    }
                    foreach (array_intersect($columns, $tables[$table]) as $column) {
                        $found[$table . '.' . $column][] = $line;
                    }
                }
            }
        }
        ksort($found);

        return array_map(static fn (array $lines): array => array_values(array_unique($lines)), $found);
    }

    /**
     * The writes in one assembled string: [tables written ('?' unread),
     * columns named (null when they cannot be read)].
     *
     * @return list<array{0: list<string>, 1: list<string>|null}>
     */
    public static function statements(string $text): array
    {
        $out = [];
        // INSERT / REPLACE [modifiers] [INTO] table
        $insert = '/\b(INSERT|REPLACE)\b(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*(\s+INTO)?\s*'
            . self::TABLE . '/i';
        if (preg_match_all($insert, $text, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($ms as $m) {
                $named = ($m[3][1] ?? -1) >= 0 && $m[3][0] !== '';
                if (!$named && !self::startsTheString($text, $m[0][1])) {
                    // A table the scan cannot read, after a keyword inside a
                    // string, is prose ("could not insert " . $what).
                    continue;
                }
                $rest = substr($text, $m[0][1] + strlen($m[0][0]));
                $out[] = [[$named ? strtolower($m[3][0]) : '?'], $named ? self::insertColumns($rest) : null];
            }
        }
        // UPDATE [modifiers] table [alias] [JOIN table …] SET, never an upsert's
        // ON DUPLICATE KEY UPDATE or a SELECT … FOR UPDATE.
        $update = '/\bUPDATE\b(?:\s+(?:LOW_PRIORITY|IGNORE))*\s*' . self::TABLE . '/i';
        if (preg_match_all($update, $text, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($ms as $m) {
                if (preg_match('/\b(?:KEY|FOR)\s*$/i', substr($text, 0, $m[0][1])) === 1) {
                    continue;
                }
                $named = ($m[1][1] ?? -1) >= 0 && $m[1][0] !== '';
                if (!$named && !self::startsTheString($text, $m[0][1])) {
                    continue;
                }
                $rest = substr($text, $m[0][1] + strlen($m[0][0]));
                $set = preg_match('/\bSET\b/i', $rest, $s, PREG_OFFSET_CAPTURE) === 1 ? $s[0][1] : null;
                $targets = [$named ? strtolower($m[1][0]) : '?'];
                if ($set !== null && preg_match_all('/`?(202_\w+)`?/', substr($rest, 0, $set), $joined) > 0) {
                    $targets = array_values(array_unique([...$targets, ...array_map('strtolower', $joined[1])]));
                }
                if ($set === null) {
                    $out[] = [$targets, null];
                    continue;
                }
                $clause = substr($rest, $set + 3);
                $clause = preg_split('/\b(?:WHERE|ORDER\s+BY|LIMIT)\b/i', $clause, 2)[0];
                $out[] = [$targets, self::assignedColumns($clause)];
            }
        }

        return $out;
    }

    /** Whether only whitespace and SQL comments come before $offset. */
    private static function startsTheString(string $text, int $offset): bool
    {
        return preg_match('/^\s*(?:(?:\/\*.*?\*\/|(?:--|#)[^\n]*\n)\s*)*$/s', substr($text, 0, $offset)) === 1;
    }

    /**
     * The columns an INSERT names after its table: a column list, or a SET
     * clause (and an upsert's ON DUPLICATE KEY UPDATE); null for no column
     * list (VALUES or SELECT: every column) or one the scan cannot read.
     *
     * @return list<string>|null
     */
    private static function insertColumns(string $rest): ?array
    {
        $rest = ltrim($rest);
        if ($rest !== '' && $rest[0] === '(') {
            $close = strpos($rest, ')');
            if ($close === false) {
                return null;
            }
            $list = substr($rest, 1, $close - 1);
            if (str_contains($list, self::U) || preg_match('/\bSELECT\b/i', $list) === 1) {
                return null;
            }
            $columns = [];
            foreach (explode(',', $list) as $c) {
                $c = trim($c, " \t\n\r`");
                if ($c === '') {
                    return null;
                }
                $columns[] = strtolower($c);
            }
            $upsert = preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b(.*)$/is', substr($rest, $close), $u) === 1
                ? self::assignedColumns($u[1]) : [];

            return $upsert === null ? null : array_values(array_unique([...$columns, ...$upsert]));
        }
        if (preg_match('/^SET\b(.*)$/is', $rest, $m) === 1) {
            return self::assignedColumns(preg_replace('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', ',', $m[1]) ?? '');
        }

        // VALUES, SELECT, nothing, or a part the scan cannot read.
        return null;
    }

    /**
     * The columns a SET clause assigns (`col = …`, `t.col = …`), or null
     * when one is in a part the scan cannot read or none is there at all.
     *
     * @return list<string>|null
     */
    private static function assignedColumns(string $clause): ?array
    {
        // Values are not columns: drop quoted strings first, keeping the
        // unread parts inside them out of the column positions.
        $bare = preg_replace("/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"/s", "''", $clause) ?? $clause;
        // An unread part where a column goes: right after SET's start or a comma.
        if (preg_match('/(?:^|,)\s*\x00/', $bare) === 1) {
            return null;
        }
        if (preg_match_all('/(?:^|,)\s*(?:`?\w+`?\.)?`?(\w+)`?\s*=/', $bare, $m) === 0) {
            return null;
        }

        return array_values(array_unique(array_map('strtolower', $m[1])));
    }
}
