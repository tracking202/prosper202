<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\RuntimeWriteTables;
use Tests\Support\SourceScan;

/**
 * A customer's updated_at says when its row last changed: GET
 * /ltv/customers/{id} answers it, and it is what a reader asking for the
 * customers changed since a time would read. Every served write of a
 * 202_customers row sets it: an UPDATE assigns it for each alias of the
 * table it writes, and an INSERT or REPLACE names it (and assigns it in an
 * ON DUPLICATE KEY UPDATE). The company-linking sweep's link did not, so a
 * customer it attached kept the time of its last other change.
 *
 * Read from the strings as PHP assembles them. A statement whose SET clause
 * or table is built at run time is listed per file, with what sets
 * updated_at in it or what names its tables (CLAUDE.md #20); in a
 * multi-table UPDATE an assignment that names no table is refused, since the
 * scan cannot tell whose column it is. Not seen: a statement split across
 * separate strings.
 */
final class CustomerWritesStampUpdatedAtTest extends TestCase
{
    private const TABLE = '202_customers';

    /** file => [customer UPDATEs whose SET is built at run time, what puts updated_at in it] */
    private const SET_BUILT = [
        '202-config/Ltv/MysqlCustomerCrmRepository.php' => [1, 'applyCrmFields() appends updated_at = ? to every'
            . ' SET it runs'],
    ];

    /** file => [UPDATEs whose table is built at run time, what names their tables] */
    private const TABLE_BUILT = [
        '202-config/Conversion/Ledger/MysqlConversionLedger.php' => [2, '202_clicks and 202_clicks_spy, by name'],
        '202-config/Crud/MysqlCrudRepository.php' => [2, 'its TableConfig (testTheGenericWritersWriteNoCustomerRow)'],
        '202-config/Ltv/MysqlCustomerCrmRepository.php' => [1, 'merge() repoints customer_id in the tables it'
            . ' lists by name, which 202_customers is not one of'],
        'api/v3/Controller.php' => [3, 'each CRUD controller\'s tableName() (testTheGenericWritersWriteNoCustomerRow)'],
    ];

    public function testEveryCustomerWriteStampsUpdatedAt(): void
    {
        $problems = [];
        $setBuilt = [];
        $tableBuilt = [];
        $writes = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
                foreach (self::verdicts($text) as $verdict) {
                    if ($verdict === 'table built') {
                        $tableBuilt[$path] = ($tableBuilt[$path] ?? 0) + 1;
                        continue;
                    }
                    $writes++;
                    if ($verdict === 'set built') {
                        $setBuilt[$path] = ($setBuilt[$path] ?? 0) + 1;
                    } elseif ($verdict !== 'stamped') {
                        $problems[] = "$path:$line $verdict";
                    }
                }
            }
        }

        self::assertSame([], $problems, "A write of a customer row leaves updated_at as it was, so the change reads"
            . " as older than it is:\n  " . implode("\n  ", $problems));
        self::assertGreaterThanOrEqual(15, $writes, 'the scan finds the customer writes the tree has');
        $lists = [[self::SET_BUILT, $setBuilt, 'SET'], [self::TABLE_BUILT, $tableBuilt, 'table']];
        foreach ($lists as [$list, $found, $what]) {
            $listed = array_map(static fn (array $entry): int => $entry[0], $list);
            ksort($listed);
            ksort($found);
            self::assertSame($listed, $found, "A statement whose $what is built at run time, unlisted or gone:"
                . ' read what builds it, then list it with that');
        }
    }

    public function testTheGenericWritersWriteNoCustomerRow(): void
    {
        $controllers = RuntimeWriteTables::controllers();
        $configs = RuntimeWriteTables::tableConfigs();
        self::assertGreaterThanOrEqual(8, count($controllers), 'every CRUD controller is read');
        self::assertGreaterThanOrEqual(5, count($configs));
        self::assertNotContains(self::TABLE, $controllers, 'api/v3/Controller.php');
        self::assertNotContains(self::TABLE, $configs, '202-config/Crud/MysqlCrudRepository.php');
    }

    /** @return array<string, array{string, list<string>}> */
    public static function shapes(): array
    {
        $u = AssembledStrings::UNREAD;

        return [
            'stamped' => ['UPDATE 202_customers SET company = ?, updated_at = ? WHERE customer_id = ?', ['stamped']],
            'the link as it was' => [
                'UPDATE 202_customers SET company_id = ?, company = ? WHERE customer_id = ? AND user_id = ?',
                ['without updated_at'],
            ],
            'backquoted, lowercase' => ['update `202_customers` set `updated_at` = ?', ['stamped']],
            'LOW_PRIORITY IGNORE' => ['UPDATE LOW_PRIORITY IGNORE 202_customers SET mrr = 0', ['without updated_at']],
            'an alias' => ['UPDATE 202_customers c SET c.company = ? WHERE c.customer_id = ?', ['without updated_at']],
            'an alias, stamped' => ['UPDATE 202_customers AS c SET c.company = ?, c.updated_at = ?', ['stamped']],
            'an alias, stamped unqualified' => ['UPDATE 202_customers c SET company = ?, updated_at = ?', ['stamped']],
            'a join writing the customer' => [
                'UPDATE 202_customers c JOIN 202_companies co ON co.company_id = c.company_id SET c.company = co.name',
                ['without updated_at'],
            ],
            'a join, stamped' => [
                'UPDATE 202_customers c JOIN 202_companies co ON co.company_id = c.company_id'
                    . ' SET c.company = co.name, c.updated_at = ? WHERE c.company <> co.name',
                ['stamped'],
            ],
            'a join stamping the other table' => [
                'UPDATE 202_customers c JOIN 202_companies co ON co.company_id = c.company_id'
                    . ' SET c.company = co.name, co.updated_at = ?',
                ['without updated_at'],
            ],
            'customers only joined' => [
                'UPDATE 202_companies co JOIN 202_customers c ON c.company_id = co.company_id SET co.name = c.company',
                [],
            ],
            'a self-join, the written side stamped' => [
                'UPDATE 202_customers t JOIN 202_customers s ON s.customer_id = ? SET t.mrr = s.mrr, t.updated_at = ?',
                ['stamped'],
            ],
            'a self-join, the read side stamped' => [
                'UPDATE 202_customers t JOIN 202_customers s ON s.customer_id = ? SET t.mrr = s.mrr, s.updated_at = ?',
                ['without updated_at', 'stamped'],
            ],
            'a join, an assignment naming no table' => [
                'UPDATE 202_customers c LEFT JOIN (SELECT customer_id, SUM(mrr) AS mrr FROM 202_subscriptions'
                    . ' GROUP BY customer_id) s ON s.customer_id = c.customer_id SET mrr = s.mrr, c.updated_at = ?',
                ['an assignment that names no table'],
            ],
            'a derived table holding customers' => [
                'UPDATE 202_companies co JOIN (SELECT company_id FROM 202_customers c GROUP BY company_id) x'
                    . ' ON x.company_id = co.company_id SET co.name = ?',
                [],
            ],
            'an interpolated time' => [
                "UPDATE 202_customers c SET c.mrr = 0, c.updated_at = $u WHERE c.customer_id IN ($u)",
                ['stamped'],
            ],
            'a SET built at run time' => ["UPDATE 202_customers SET $u WHERE customer_id = ?", ['set built']],
            'a table built at run time' => ["UPDATE $u SET customer_id = ? WHERE customer_id = ?", ['table built']],
            'a sprintf() table' => ['UPDATE %s SET %s = ? WHERE %s = ?', ['table built']],
            'a name ending at run time' => ["UPDATE 202_customers$u SET company = ?", ['table built']],
            'a table joined at run time' => [
                "UPDATE 202_customers c JOIN $u x ON x.id = c.customer_id SET c.mrr = 0, c.updated_at = ?",
                ['table built'],
            ],
            'a longer name' => ['UPDATE 202_customers_archive SET company = ?', []],
            'another customer table' => ['UPDATE 202_customer_aliases SET customer_id = ?', []],
            'a column that only starts so' => ['UPDATE 202_customers SET updated_at_ms = ?', ['without updated_at']],
            'updated_at in the WHERE only' => [
                'UPDATE 202_customers SET company = ? WHERE updated_at < ?',
                ['without updated_at'],
            ],
            'updated_at read in a subquery' => [
                'UPDATE 202_customers SET company = (SELECT updated_at FROM 202_companies LIMIT 1), mrr = 0',
                ['without updated_at'],
            ],
            'updated_at inside a literal' => [
                "UPDATE 202_customers SET status = 'x, updated_at = 1' WHERE customer_id = ?",
                ['without updated_at'],
            ],
            'a comma inside a literal' => [
                "UPDATE 202_customers SET company = 'a, b', updated_at = ? WHERE customer_id = ?",
                ['stamped'],
            ],
            'an INSERT naming it' => [
                'INSERT INTO 202_customers (user_id, primary_ref, created_at, updated_at) VALUES (?, ?, ?, ?)',
                ['stamped'],
            ],
            'an INSERT without it' => [
                'INSERT INTO 202_customers (user_id, primary_ref) VALUES (?, ?)',
                ['without updated_at'],
            ],
            'an INSERT whose update leaves it' => [
                'INSERT INTO 202_customers (user_id, primary_ref, updated_at) VALUES (?, ?, ?)'
                    . ' ON DUPLICATE KEY UPDATE company = VALUES(company)',
                ['without updated_at'],
            ],
            'an INSERT whose update stamps it' => [
                'INSERT IGNORE INTO `202_customers` (user_id, updated_at) VALUES (?, ?)'
                    . ' ON DUPLICATE KEY UPDATE company = VALUES(company), updated_at = VALUES(updated_at)',
                ['stamped'],
            ],
            'an INSERT … SET naming it' => ['INSERT INTO 202_customers SET user_id = ?, updated_at = ?', ['stamped']],
            'an INSERT … SELECT' => [
                'INSERT INTO 202_customers SELECT * FROM 202_customers_old',
                ['an INSERT that names no columns'],
            ],
            'updated_at only in the SELECT' => [
                'INSERT INTO 202_customers (user_id, company) SELECT user_id, updated_at FROM 202_companies',
                ['without updated_at'],
            ],
            'a REPLACE without it' => [
                'REPLACE INTO 202_customers (customer_id, company) VALUES (?, ?)',
                ['without updated_at'],
            ],
            'another table\'s INSERT' => [
                'INSERT INTO 202_customer_aliases (user_id, customer_id) VALUES (?, ?)'
                    . ' ON DUPLICATE KEY UPDATE customer_id = customer_id',
                [],
            ],
            'prose' => ['update the customers set by the sweep', []],
        ];
    }

    /**
     * @dataProvider shapes
     * @param list<string> $verdicts
     */
    public function testTheScanReadsEachShape(string $sql, array $verdicts): void
    {
        self::assertSame($verdicts, self::verdicts($sql), $sql);
    }

    /**
     * One verdict per write of a customer row in an assembled string:
     * 'stamped', 'without updated_at', 'an assignment that names no table',
     * 'set built', or 'table built' for an UPDATE whose table is not read.
     *
     * @return list<string>
     */
    private static function verdicts(string $text): array
    {
        $blanked = self::blankLiterals($text);
        $out = [];
        $found = preg_match_all(
            '/\b(?:(INSERT|REPLACE)(?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*\s+(?:INTO\s+)?'
                . '|UPDATE(?:\s+(?:LOW_PRIORITY|IGNORE))*\s+)/i',
            $blanked,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        if ($found === false) {
            throw new \RuntimeException('Scanning a string failed: ' . preg_last_error_msg());
        }
        foreach ($matches[0] as $i => [$keyword, $offset]) {
            $rest = substr($blanked, $offset + strlen($keyword));
            if (($matches[1][$i][1] ?? -1) < 0 && self::found('/\bKEY\s*$/i', substr($blanked, 0, $offset))) {
                continue; // an INSERT's ON DUPLICATE KEY UPDATE, read with its INSERT
            }
            if (($matches[1][$i][1] ?? -1) >= 0) {
                $verdict = self::insertVerdict($rest);
                if ($verdict !== null) {
                    $out[] = $verdict;
                }
                continue;
            }
            array_push($out, ...self::updateVerdicts($rest));
        }

        return $out;
    }

    private static function insertVerdict(string $rest): ?string
    {
        if (!self::found('/^`?' . self::TABLE . '`?(?![\w\x00])\s*/i', $rest, $m)) {
            return null;
        }
        $after = substr($rest, strlen($m[0]));
        $odku = self::depthZero($after, '/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i');
        $insert = $odku === null ? $after : substr($after, 0, $odku);
        if (self::found('/^\(([^()]*)\)/', $insert, $columns)) {
            $named = in_array('updated_at', array_map(
                static fn (string $column): string => strtolower(trim($column, " \t\n\r`")),
                explode(',', $columns[1])
            ), true);
        } elseif (self::found('/^SET\b/i', $insert)) {
            $named = self::assignsUpdatedAt(self::assignmentTargets(substr($insert, 3)));
        } else {
            return 'an INSERT that names no columns';
        }
        if (!$named) {
            return 'without updated_at';
        }
        if ($odku === null) {
            return 'stamped';
        }
        self::found('/^ON\s+DUPLICATE\s+KEY\s+UPDATE/i', substr($after, $odku), $keyword);

        return self::assignsUpdatedAt(self::assignmentTargets(substr($after, $odku + strlen($keyword[0]))))
            ? 'stamped'
            : 'without updated_at';
    }

    /** @return list<string> */
    private static function updateVerdicts(string $rest): array
    {
        $set = self::depthZero($rest, '/\bSET\b/i');
        if ($set === null) {
            return [];
        }
        $refs = substr($rest, 0, $set);
        $positions = preg_split('/\bJOIN\b|,/i', self::flatten($refs));
        if ($positions === false) {
            throw new \RuntimeException('Scanning a string failed: ' . preg_last_error_msg());
        }
        $tables = [];
        $references = 0;
        $built = false;
        foreach ($positions as $position) {
            if (self::found('/^\s*$/', $position)) {
                continue;
            }
            $references++;
            if (self::found('/^\s*\(/', $position)) {
                continue;
            }
            // A name the scan cannot read (an interpolation, sprintf's %s)
            // may be this table: listed, never read as some other one.
            $named = self::found('/^\s*`?(\w+)`?(?![\w\x00%`])(?:\s+(?:AS\s+)?'
                . '(?!(?:ON|USING|LEFT|RIGHT|INNER|OUTER|CROSS|STRAIGHT_JOIN|NATURAL)\b)(\w+))?/i', $position, $m);
            if (!$named) {
                $built = true;
                continue;
            }
            $tables[] = [strtolower($m[1]), isset($m[2]) && $m[2] !== '' ? strtolower($m[2]) : strtolower($m[1])];
        }
        if ($built) {
            return ['table built'];
        }
        $customers = array_values(array_filter($tables, static fn (array $t): bool => $t[0] === self::TABLE));
        if ($customers === []) {
            return [];
        }

        $clause = substr($rest, $set + 3);
        $where = self::depthZero($clause, '/\b(?:WHERE|ORDER\s+BY|LIMIT)\b/i');
        $clause = $where === null ? $clause : substr($clause, 0, $where);
        $targets = self::assignmentTargets($clause);
        if ($targets === [] || str_contains(self::flatten($clause), "\x00") && !self::assignsUpdatedAt($targets)) {
            return ['set built'];
        }

        $single = $references === 1;
        $out = [];
        foreach ($customers as [, $alias]) {
            $written = false;
            $stamped = false;
            foreach ($targets as [$qualifier, $column]) {
                if ($qualifier === null && !$single) {
                    return ['an assignment that names no table'];
                }
                if ($qualifier === null || $qualifier === $alias) {
                    $written = true;
                    $stamped = $stamped || $column === 'updated_at';
                }
            }
            if ($written) {
                $out[] = $stamped ? 'stamped' : 'without updated_at';
            }
        }

        return $out;
    }

    /** @param list<array{?string, string}> $targets */
    private static function assignsUpdatedAt(array $targets): bool
    {
        foreach ($targets as [, $column]) {
            if ($column === 'updated_at') {
                return true;
            }
        }

        return false;
    }

    /**
     * The column each depth-0 assignment of a SET list writes, with the
     * table or alias that qualifies it (null when none does).
     *
     * @return list<array{?string, string}>
     */
    private static function assignmentTargets(string $list): array
    {
        $targets = [];
        foreach (explode(',', self::flatten($list)) as $assignment) {
            if (self::found('/^\s*(?:`?(\w+)`?\.)?`?(\w+)`?\s*=/', $assignment, $m)) {
                $targets[] = [$m[1] !== '' ? strtolower($m[1]) : null, strtolower($m[2])];
            }
        }

        return $targets;
    }

    /** The text with everything inside parentheses removed, so what is left is at depth 0. */
    private static function flatten(string $text): string
    {
        $out = '';
        $depth = 0;
        foreach (str_split($text) as $char) {
            if ($char === '(') {
                $depth++;
                $out .= $depth === 1 ? '(' : '';
                continue;
            }
            if ($char === ')') {
                $depth = max(0, $depth - 1);
                $out .= $depth === 0 ? ')' : '';
                continue;
            }
            if ($depth === 0) {
                $out .= $char;
            }
        }

        return $out;
    }

    /** Offset in $text of the first match of $pattern outside every parenthesis, or null. */
    private static function depthZero(string $text, string $pattern): ?int
    {
        $flat = '';
        $depth = 0;
        foreach (str_split($text) as $char) {
            $paren = $char === '(' || $char === ')';
            $depth = max(0, $depth + ($char === '(' ? 1 : 0) - ($char === ')' ? 1 : 0));
            $flat .= $depth === 0 && !$paren ? $char : ' ';
        }

        return self::found($pattern, $flat, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;
    }

    /** Quoted SQL literals replaced by a `?`, so what they hold reads as nothing. */
    private static function blankLiterals(string $text): string
    {
        $blanked = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $text);
        if ($blanked === null) {
            throw new \RuntimeException('Scanning a string failed: ' . preg_last_error_msg());
        }

        return $blanked;
    }

    /**
     * preg_match() that refuses to read a failed match as no match.
     *
     * @param array<mixed>|null $m
     */
    private static function found(string $pattern, string $subject, ?array &$m = null, int $flags = 0): bool
    {
        $found = preg_match($pattern, $subject, $m, $flags);
        if ($found === false) {
            throw new \RuntimeException('Scanning a string failed: ' . preg_last_error_msg());
        }

        return $found === 1;
    }
}
