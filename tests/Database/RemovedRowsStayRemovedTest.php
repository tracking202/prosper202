<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Tests\Support\SqlJoinScan;

/**
 * A row the account removed is not read as a live one.
 *
 * Setup removes a traffic source's custom variable by marking it
 * (202_ppc_network_variables.deleted = 1), and the Setup page, the link
 * generator and the API read the live ones only. Get Trackers, the
 * redirects and the landing-page scripts read the traffic source's
 * variables through one derived table each — `(SELECT ppc_network_id,
 * GROUP_CONCAT(parameter) … FROM 202_ppc_network_variables GROUP BY
 * ppc_network_id)` — with no `deleted = 0`, so:
 *
 *  - Get Trackers showed every tracking link with the removed variable on it
 *    (measured live: `&goneparam={gone}` on a link whose source had removed
 *    it, while GET /trackers/{id}/url left it off);
 *  - the redirects went on recording a removed variable's value, and a
 *    variable removed and added again under the same parameter was recorded
 *    twice, once per row; the Custom Variables report, grouping by name,
 *    then counted that click twice (measured live: "Gone VALX 2" for one
 *    click, with the report's total at 1).
 *
 * Two invariants, read from the SQL every PHP file writes (SqlJoinScan's
 * string chains and tokens, so concatenation, heredocs and interpolation are
 * read as they are for AccountScopedJoinTest):
 *
 *  1. every read of 202_ppc_network_variables (the table after FROM or
 *     JOIN, in a statement that is not a write) has `[alias.]deleted = 0`
 *     in its own query level — its WHERE, or the ON clause of its join —
 *     with no OR beside it at that level;
 *  2. every derived table (`FROM (SELECT …` or `JOIN (SELECT …`) over a
 *     table with a soft-delete column (`deleted`, or `<name>_deleted`, read
 *     from the schema's definitions) has that column `= 0` in its own WHERE,
 *     the same way. A derived table is where a read loses the outer query's
 *     filters: the join that follows it cannot see which of its rows were
 *     removed.
 *
 * A read that has to see removed rows is listed in ALLOWED with why. What
 * the scan does not see: a statement whose table name or condition is built
 * at runtime (a hole is not `deleted = 0`, so such a read fails rather than
 * passes), a read through a view or a stored procedure, and a removed row
 * reached by id from another row (a click naming a removed variable) — that
 * is history, and the reports keep it.
 */
final class RemovedRowsStayRemovedTest extends TestCase
{
    private const PRUNED = [
        'vendor', 'node_modules', '.git', '.claude', 'tests', 'docs', 'documentation', 'go-cli', 'sdk',
        '202-css', '202-img', '202-js',
    ];

    /** The schema's own definitions and the static-analysis rules: DDL and test fixtures, no reads. */
    private const SKIPPED_FILES = ['202-config/Database/Tables/', '202-config/PHPStan/'];

    /** Statements that write, by their first word: a removal marks rows, it does not read them as live. */
    private const WRITES = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];

    private const VARIABLES = '202_ppc_network_variables';

    /**
     * Reads that see removed rows on purpose. Keyed file => [finding key =>
     * why]; testEveryAllowedReadStillExists() keeps the list honest.
     *
     * @var array<string, array<string, string>>
     */
    private const ALLOWED = [
        '202-config/class-dataengine.php' => [
            'read | 202_ppc_network_variables' => 'the Custom Variables report names the variable each recorded value'
                . ' belongs to: a removed variable\'s values are still its clicks\', so its name is read and shown'
                . ' as removed',
        ],
    ];

    /** @var array<string, string>|null table => its soft-delete column */
    private static ?array $softDeleted = null;

    /** @var array<string, list<array{line: int, key: string}>>|null */
    private static ?array $tree = null;

    /** @return array<string, string> lower-case table => its soft-delete column, for every definition that has one */
    public static function softDeletedTables(): array
    {
        if (self::$softDeleted !== null) {
            return self::$softDeleted;
        }
        $root = dirname(__DIR__, 2);
        $files = glob($root . '/202-config/Database/Tables/*Tables.php');
        if ($files === false || $files === []) {
            throw new \RuntimeException('no table definitions under 202-config/Database/Tables');
        }
        $tables = [];
        foreach ($files as $file) {
            $class = 'Prosper202\\Database\\Tables\\' . basename($file, '.php');
            foreach ($class::getDefinitions() as $definition) {
                if (preg_match('/^\s*`(\w*deleted)`\s+tinyint/mi', $definition->createStatement, $m) === 1) {
                    $tables[strtolower($definition->tableName)] = strtolower($m[1]);
                }
            }
        }

        return self::$softDeleted = $tables;
    }

    /**
     * Every read that does not skip removed rows, for one PHP source.
     *
     * @return list<array{line: int, key: string}>
     */
    public static function scan(string $source): array
    {
        $out = [];
        foreach (SqlJoinScan::chains($source) as $chain) {
            $tokens = SqlJoinScan::sqlTokens($chain['text'], $chain['holes']);
            if ($tokens === [] || in_array($tokens[0]['u'], self::WRITES, true)) {
                continue;
            }
            foreach (self::findings($tokens) as $key) {
                $out[] = ['line' => $chain['line'], 'key' => $key];
            }
        }

        return $out;
    }

    /**
     * @param list<array{k: string, v: string, u: string}> $tokens
     * @return list<string> one key per read that does not skip removed rows
     */
    public static function findings(array $tokens): array
    {
        $n = count($tokens);
        $depth = [];
        $d = 0;
        foreach ($tokens as $i => $t) {
            if ($t['k'] === 'punct' && $t['v'] === ')') {
                $d--;
            }
            $depth[$i] = $d;
            if ($t['k'] === 'punct' && $t['v'] === '(') {
                $d++;
            }
        }
        $soft = self::softDeletedTables();
        $out = [];
        for ($i = 1; $i < $n; $i++) {
            $prev = $tokens[$i - 1];
            $t = $tokens[$i];
            $afterFromOrJoin = $prev['k'] === 'word' && in_array($prev['u'], ['FROM', 'JOIN', 'STRAIGHT_JOIN'], true);
            $isTable = in_array($t['k'], ['word', 'name'], true);

            // 1. A read of the variables table.
            if ($afterFromOrJoin && $isTable && strtolower($t['v']) === self::VARIABLES) {
                if (!self::skipsRemoved($tokens, $depth, $i, 'deleted')) {
                    $out[] = 'read | ' . self::VARIABLES;
                }
                continue;
            }

            // 2. A derived table over a soft-deleted table.
            $opensSelect = $t['k'] === 'punct' && $t['v'] === '(' && ($tokens[$i + 1]['u'] ?? '') === 'SELECT';
            if ($afterFromOrJoin && $opensSelect) {
                $inner = $depth[$i] + 1;
                for ($j = $i + 2; $j < $n && $depth[$j] >= $inner; $j++) {
                    if ($depth[$j] !== $inner || $tokens[$j]['u'] !== 'FROM') {
                        continue;
                    }
                    $table = $tokens[$j + 1] ?? null;
                    $named = $table !== null && in_array($table['k'], ['word', 'name'], true);
                    $name = $named ? strtolower($table['v']) : '';
                    $checked = isset($soft[$name]) && $name !== self::VARIABLES;
                    if ($checked && !self::skipsRemoved($tokens, $depth, $j + 1, $soft[$name])) {
                        $out[] = 'derived | ' . $name;
                    }
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Whether the query level the table at $at is read in has
     * `[alias.]$column = 0` — at that level, or in the parenthesized ON
     * clause of the table's own join — and no OR, XOR or `||` at that level.
     *
     * @param list<array{k: string, v: string, u: string}> $tokens
     * @param array<int, int> $depth
     */
    private static function skipsRemoved(array $tokens, array $depth, int $at, string $column): bool
    {
        $n = count($tokens);
        $level = $depth[$at];
        $start = $at;
        while ($start > 0 && $depth[$start - 1] >= $level) {
            $start--;
        }
        $end = $at;
        while ($end + 1 < $n && $depth[$end + 1] >= $level) {
            $end++;
        }
        $names = [strtolower($tokens[$at]['v'])];
        $next = $tokens[$at + 1] ?? null;
        if ($next !== null && $next['u'] === 'AS') {
            $next = $tokens[$at + 2] ?? null;
        }
        $clause = ['ON', 'USING', 'WHERE', 'GROUP', 'ORDER', 'LIMIT', 'LEFT', 'RIGHT', 'INNER', 'JOIN', 'CROSS',
            'STRAIGHT_JOIN', 'HAVING', 'UNION'];
        if ($next !== null && in_array($next['k'], ['word', 'name'], true) && !in_array($next['u'], $clause, true)) {
            $names[] = strtolower($next['v']);
        }
        // The parenthesized ON clause of this table's own join counts as its level.
        $onGroup = null;
        $onStart = 0;
        for ($k = $at + 1; $k <= $end && $k <= $at + 4; $k++) {
            $parenNext = ($tokens[$k + 1]['k'] ?? '') === 'punct' && ($tokens[$k + 1]['v'] ?? '') === '(';
            if ($depth[$k] === $level && $tokens[$k]['u'] === 'ON' && $parenNext) {
                $onGroup = $depth[$k + 1] + 1;
                $onStart = $k + 2;
                break;
            }
        }
        $found = false;
        for ($k = $start; $k <= $end; $k++) {
            $inOn = $onGroup !== null && $k >= $onStart && $depth[$k] === $onGroup
                && self::inGroup($depth, $onStart, $k, $onGroup);
            $here = $depth[$k] === $level || $inOn;
            if (!$here) {
                continue;
            }
            $t = $tokens[$k];
            if (in_array($t['u'], ['OR', 'XOR'], true) || ($t['k'] === 'op' && $t['v'] === '||')) {
                return false;
            }
            if (!in_array($t['k'], ['word', 'name'], true) || strtolower($t['v']) !== $column) {
                continue;
            }
            $qualified = $k >= 2 && $tokens[$k - 1]['v'] === '.' && $tokens[$k - 1]['k'] === 'punct';
            if ($qualified && !in_array(strtolower($tokens[$k - 2]['v']), $names, true)) {
                continue;
            }
            $eq = $tokens[$k + 1] ?? null;
            $zero = $tokens[$k + 2] ?? null;
            $isZero = $zero !== null && in_array($zero['k'], ['word', 'str'], true) && $zero['v'] === '0';
            if ($eq !== null && $eq['k'] === 'op' && $eq['v'] === '=' && $isZero) {
                $found = true;
            }
        }

        return $found;
    }

    /** Whether $k is still inside the parenthesized group that opened just before $from. */
    private static function inGroup(array $depth, int $from, int $k, int $group): bool
    {
        for ($j = $from; $j <= $k; $j++) {
            if ($depth[$j] < $group) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, list<array{line: int, key: string}>> repo-relative file => its findings */
    private static function treeFindings(): array
    {
        if (self::$tree !== null) {
            return self::$tree;
        }
        $root = dirname(__DIR__, 2);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            \Tests\Support\SourceScan::tree($root),
            static fn (\SplFileInfo $f): bool => !in_array($f->getFilename(), self::PRUNED, true)
        ));
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($files);
        self::assertGreaterThan(500, count($files), 'the tree walk found almost no PHP');
        $out = [];
        foreach ($files as $path) {
            foreach (self::SKIPPED_FILES as $skipped) {
                if (str_starts_with($path, $skipped)) {
                    continue 2;
                }
            }
            $source = file_get_contents($root . '/' . $path);
            if ($source === false) {
                throw new \RuntimeException("Could not read $path");
            }
            if (stripos($source, 'from') === false && stripos($source, 'join') === false) {
                continue;
            }
            foreach (self::scan($source) as $f) {
                $out[$path][] = $f;
            }
        }

        return self::$tree = $out;
    }

    public function testTheSoftDeletedTablesComeFromTheSchema(): void
    {
        $tables = self::softDeletedTables();
        self::assertSame('deleted', $tables[self::VARIABLES] ?? null);
        self::assertSame('aff_campaign_deleted', $tables['202_aff_campaigns'] ?? null);
        self::assertSame('ppc_account_deleted', $tables['202_ppc_accounts'] ?? null);
        self::assertGreaterThanOrEqual(7, count($tables));
    }

    public function testNoReadTakesRemovedRowsForLiveOnes(): void
    {
        $unlisted = [];
        $variableReads = 0;
        foreach (self::treeFindings() as $path => $findings) {
            foreach ($findings as $f) {
                if (isset(self::ALLOWED[$path][$f['key']])) {
                    continue;
                }
                $unlisted[] = "$path:{$f['line']}  {$f['key']}";
            }
        }
        foreach (self::treeFindings() as $findings) {
            $variableReads += count($findings);
        }
        self::assertSame([], $unlisted, "these reads take rows the account removed for live ones:\n  "
            . implode("\n  ", $unlisted)
            . "\nAdd `deleted = 0` (the table's soft-delete column) to the read's own WHERE, or list it in"
            . ' ALLOWED with why it must see removed rows.');
        self::assertGreaterThan(0, $variableReads, 'the allowed reads were not found either: the scan saw nothing');
    }

    public function testEveryAllowedReadStillExists(): void
    {
        $tree = self::treeFindings();
        foreach (self::ALLOWED as $path => $entries) {
            $keys = array_column($tree[$path] ?? [], 'key');
            foreach (array_keys($entries) as $key) {
                self::assertContains($key, $keys, "ALLOWED lists '$key' in $path, which no longer reads it:"
                    . ' remove the entry');
            }
        }
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function spellings(): array
    {
        $v = self::VARIABLES;
        $q = static fn (string $sql): string => "<?php \$s = '" . $sql . "';";
        $derived = static fn (string $where): string => $q(
            'SELECT 1 FROM t LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(parameter) AS p'
            . " FROM $v $where GROUP BY ppc_network_id) AS cv USING (ppc_network_id)"
        );
        $campaigns = static fn (string $where): string => $q(
            "SELECT 1 FROM t JOIN (SELECT aff_campaign_id FROM 202_aff_campaigns $where) d USING (aff_campaign_id)"
        );
        $read = ["read | $v"];

        return [
            'derived table, no filter' => [$derived(''), $read],
            'derived table, filtered' => [$derived('WHERE deleted = 0'), []],
            'filter quoted' => ["<?php \$s = \"SELECT * FROM $v WHERE ppc_network_id = '1' AND deleted = '0'\";", []],
            'filter qualified by alias' => [$q("SELECT pv.name FROM $v AS pv WHERE pv.deleted = 0"), []],
            'filter on another alias' => [
                $q("SELECT pv.name FROM $v AS pv JOIN x ON (x.a = pv.a) WHERE x.deleted = 0"),
                $read,
            ],
            'filter beside an OR' => [$q("SELECT name FROM $v WHERE deleted = 0 OR 1 = 1"), $read],
            'filter one level out' => [
                $q("SELECT 1 FROM t JOIN (SELECT a FROM $v GROUP BY a) d USING (a) WHERE deleted = 0"),
                $read,
            ],
            'filter in a nested subquery only' => [
                $q("SELECT name FROM $v WHERE id IN (SELECT id FROM y WHERE deleted = 0)"),
                $read,
            ],
            'filter in its own ON clause' => [
                $q("SELECT 1 FROM x LEFT JOIN $v v ON (v.ppc_network_id = x.ppc_network_id AND v.deleted = 0)"),
                [],
            ],
            'not zero' => [$q("SELECT name FROM $v WHERE deleted = 1"), $read],
            'a runtime condition' => ["<?php \$s = 'SELECT name FROM $v WHERE ' . \$where;", $read],
            'concatenated' => ["<?php \$s = 'SELECT name FROM ' . '$v' . ' WHERE deleted = 0';", []],
            'heredoc' => ["<?php \$s = <<<SQL\nSELECT name\nFROM `$v`\nWHERE deleted = 0\nSQL;\n", []],
            'a write is not a read' => [
                "<?php \$s = \"UPDATE $v SET deleted = '1' WHERE ppc_variable_id NOT IN (1)\";",
                [],
            ],
            'a delete is not a read' => ["<?php \$s = \"DELETE FROM $v WHERE ppc_network_id = '1'\";", []],
            'derived over a soft-deleted table' => [
                $campaigns('GROUP BY aff_campaign_id'),
                ['derived | 202_aff_campaigns'],
            ],
            'derived over a soft-deleted table, filtered' => [$campaigns('WHERE aff_campaign_deleted = 0'), []],
            'derived with the wrong column' => [$campaigns('WHERE deleted = 0'), ['derived | 202_aff_campaigns']],
            'derived from a FROM' => [
                $q('SELECT n FROM (SELECT ppc_account_id AS n FROM 202_ppc_accounts) d'),
                ['derived | 202_ppc_accounts'],
            ],
            'a plain join is not derived' => [
                $q('SELECT 1 FROM t JOIN 202_aff_campaigns ac ON (ac.aff_campaign_id = t.aff_campaign_id)'),
                [],
            ],
        ];
    }

    /**
     * @dataProvider spellings
     * @param list<string> $expected
     */
    public function testTheScanReadsEverySpellingItClaims(string $php, array $expected): void
    {
        self::assertSame($expected, array_column(self::scan($php), 'key'));
    }
}
