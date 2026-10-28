<?php

declare(strict_types=1);

namespace Tests\Database;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;
use Tests\Support\SqlJoinScan;

/**
 * A page changes or removes a row it names by id only when the row is the
 * signed-in account's.
 *
 * Setup › Traffic Sources renamed a source with `UPDATE 202_ppc_networks SET
 * user_id = <the session's>, … WHERE ppc_network_id = <the posted id>`, so
 * any account that reached Setup could rename another account's traffic
 * source and take it into its own. The pages beside it read the row first
 * with `AND user_id = …` and then wrote it by id alone: right only while the
 * check and the write name the same id on every path, which nothing held.
 *
 * The invariant, per statement: an UPDATE or DELETE in a served page or
 * endpoint (SCOPE) on a table with a user_id column (read from the schema's
 * definitions, as AccountScopedJoinTest reads them) has a top-level conjunct
 * `[t.]user_id = <the account>` in its WHERE, where <the account> is `?`, a
 * fixed number (the install's own row: `user_id = 1`) or a hole whose PHP
 * source names a user id (SqlJoinScan::namesAUserId(): `$mysql['user_id']`,
 * `(int) $userId`) and is not a request superglobal. A user_id in the SET
 * list is not one. A table with no user_id column is owned through its
 * parent (a redirector's rules, a traffic source's variables): its row
 * cannot say whose it is, so each write to one is listed in THROUGH_PARENT
 * with the check that runs before it.
 *
 * What the scan reads: the SQL each PHP file writes as SqlJoinScan's string
 * chains (literals, heredocs, `.` concatenation, interpolation, sprintf()
 * formats, comments dropped), and a statement finished by later statements
 * — `$sql = "UPDATE … SET"; $sql .= "…"; $sql .= " WHERE …";` — joined in
 * source order up to the first other use of the variable, passing over the
 * INSERT or REPLACE the same builder is given in its other branch (the
 * pages' `if ($editing) { UPDATE … SET } else { INSERT … SET }` shape).
 *
 * What it refuses by name rather than reads (each fails unless listed): a
 * table built at runtime or not in the schema, a multi-table UPDATE or
 * DELETE, a statement with no WHERE it can read (none, an append it cannot
 * read, or a reassignment before the WHERE), an OR at the top of the WHERE,
 * and a WHERE with a hole whose name says it is a clause (`$where`).
 *
 * What it does not see: a statement whose first word is not literal
 * (`$verb . ' FROM …'`); a write made in a class the page calls (the
 * repositories, AccountPixels, UserDataPurge — the API's own tests hold
 * those); whether the value a user-id name holds is the session's (it is
 * trusted by its name, as AccountScopedJoinTest trusts it); and whether a
 * conditional append runs on the same path as the statement it finishes.
 */
final class AccountScopedWriteTest extends TestCase
{
    /** The served pages and endpoints whose writes are read. */
    private const SCOPE = [
        'tracking202/setup/', 'tracking202/ajax/', 'tracking202/update/', '202-account/',
        'api/legacy/', 'api/v1/', 'api/v2/',
    ];

    private const THROUGH = 'owned through its parent';

    private const ROTATOR_RULES = 'a rule, redirect or criterion of the redirector the request names: the'
        . ' redirector is checked to be the account\'s (`id = … AND user_id = …`, else "ERROR") and every saved'
        . ' rule, redirect and criterion the payload names to hang off it, for the whole payload before anything'
        . ' is written';
    private const ROTATOR_REMOVAL = 'the rules, criteria and redirects of the redirector removed in the same'
        . ' transaction, which first locks it `WHERE id = ? AND user_id = ?` and removes nothing when that finds'
        . ' none';
    private const SOURCE_VARIABLES = 'the variables of the traffic source the request names, which the owner'
        . ' check at the top found the account\'s own and not removed (`ppc_network_id = … AND user_id = …`), or'
        . ' the request ended with a 404; a variable id is updated only within that source';

    /**
     * Writes to a table owned through its parent, with the check that ties
     * the parent to the account first. Keyed file => ["table | condition" =>
     * why]; testEveryListedWriteStillExists() keeps the list honest, and it
     * may only shrink (CEILING).
     *
     * @var array<string, array<string, string>>
     */
    private const THROUGH_PARENT = [
        'tracking202/ajax/custom_variables.php' => [
            "202_ppc_network_variables | ppc_variable_id = '{\$variableId}'"
                . " AND ppc_network_id = '{\$ppcNetworkId}'" => self::SOURCE_VARIABLES,
            "202_ppc_network_variables | ppc_variable_id NOT IN ( {\$keptList} )"
                . " AND ppc_network_id = '{\$ppcNetworkId}'" => self::SOURCE_VARIABLES,
            "202_ppc_network_variables | ppc_network_id = '{\$ppcNetworkId}' AND deleted = '0'"
                => self::SOURCE_VARIABLES,
        ],
        'tracking202/ajax/rotator.php' => [
            "202_rotator_rules | id = '{(int)\$rule['rule_id']}'" => self::ROTATOR_RULES,
            "202_rotator_rules_redirects | id = '{\$rule_redirect_id}'" => self::ROTATOR_RULES,
            "202_rotator_rules_criteria | id = '{(int)\$criteria['criteria_id']}'" => self::ROTATOR_RULES,
            "202_rotator_rules_redirects | id NOT IN ( {\$redirects_ids} ) AND rule_id = '{\$rule_id}'"
                => self::ROTATOR_RULES,
            "202_rotator_rules | `id` NOT IN ( {\$rules_id} ) AND rotator_id = '{\$rotator_id}'"
                => self::ROTATOR_RULES,
            "202_rotator_rules_criteria | `id` NOT IN ( {\$criteria_id} ) AND rotator_id = '{\$rotator_id}'"
                => self::ROTATOR_RULES,
        ],
        'tracking202/setup/rotator.php' => [
            '202_rotator_rules_criteria | rotator_id = ?' => self::ROTATOR_REMOVAL,
            '202_rotator_rules_redirects | rule_id IN ( SELECT id FROM 202_rotator_rules WHERE rotator_id = ? )'
                => self::ROTATOR_REMOVAL,
            '202_rotator_rules | rotator_id = ?' => self::ROTATOR_REMOVAL,
        ],
    ];

    /** The number of THROUGH_PARENT entries: the list may only shrink. */
    private const CEILING = 12;

    private const STATEMENT_ENDS = ['ORDER', 'LIMIT'];

    private const JOIN_WORDS = ['JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'STRAIGHT_JOIN', 'USING'];

    /** @var array<string, true>|null */
    private static ?array $schemaTables = null;

    /** @var array<string, list<array{line: int, key: string}>>|null */
    private static ?array $tree = null;

    /** @return array<string, true> lower-case name => true, for every table the schema defines */
    private static function schemaTables(): array
    {
        if (self::$schemaTables !== null) {
            return self::$schemaTables;
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
                $tables[strtolower($definition->tableName)] = true;
            }
        }

        return self::$schemaTables = $tables;
    }

    /**
     * Every UPDATE and DELETE in one PHP source whose WHERE does not tie its
     * row to the account, in source order, as "kind | table | condition".
     *
     * @return list<array{line: int, key: string}>
     */
    public static function scan(string $source): array
    {
        $tokens = \PhpToken::tokenize($source);
        $chains = SqlJoinScan::chains($source);
        $byStart = [];
        foreach ($chains as $chain) {
            $byStart[$chain['start']] = $chain;
        }
        $out = [];
        foreach ($chains as $chain) {
            $holes = $chain['holes'];
            $sql = SqlJoinScan::sqlTokens($chain['text'], $holes);
            $target = self::target($sql);
            if ($target === null) {
                continue;
            }
            if (!isset($target['refused']) && self::whereAt($sql, $target['next']) === null) {
                [$text, $holes] = self::assembled($tokens, $byStart, $chain);
                $sql = SqlJoinScan::sqlTokens($text, $holes);
            }
            $finding = self::finding($sql, $target, $holes);
            if ($finding !== null) {
                $out[] = ['line' => $chain['line'], 'key' => $finding];
            }
        }

        return $out;
    }

    /**
     * The table an UPDATE or DELETE writes, or null when the chain is not
     * one (a label such as "Update tracking link", a log line naming one).
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @return array{table: string, alias: string, next: int, refused?: string}|null
     */
    private static function target(array $t): ?array
    {
        if ($t === [] || $t[0]['k'] !== 'word' || !in_array($t[0]['u'], ['UPDATE', 'DELETE'], true)) {
            return null;
        }
        $verb = $t[0]['u'];
        $i = 1;
        while (self::isWord($t[$i] ?? null, ['LOW_PRIORITY', 'IGNORE', 'QUICK'])) {
            $i++;
        }
        if ($verb === 'DELETE') {
            if (!self::isWord($t[$i] ?? null, ['FROM'])) {
                // DELETE t FROM 202_x t …: refused when a table follows the FROM.
                for ($j = $i; $j < min(count($t), $i + 6); $j++) {
                    $after = $t[$j + 1] ?? null;
                    if (self::isWord($t[$j], ['FROM']) && $after !== null && self::isTable($after)) {
                        $table = strtolower($after['v']);

                        return ['table' => $table, 'alias' => '', 'next' => $j, 'refused' => 'multi-table DELETE'];
                    }
                }
                return null;
            }
            $i++;
        }
        $ref = $t[$i] ?? null;
        if ($ref === null) {
            return null;
        }
        if ($ref['k'] === 'hole') {
            $table = '{' . $ref['v'] . '}';

            return ['table' => $table, 'alias' => '', 'next' => $i + 1, 'refused' => 'table built at runtime'];
        }
        if (!in_array($ref['k'], ['word', 'name'], true)) {
            return null;
        }
        $table = strtolower($ref['v']);
        $known = self::isTable($ref);
        $j = $i + 1;
        $alias = '';
        $clause = array_merge(['SET', 'WHERE', 'ORDER', 'LIMIT'], self::JOIN_WORDS);
        if (self::isWord($t[$j] ?? null, ['AS']) && in_array($t[$j + 1]['k'] ?? '', ['word', 'name'], true)) {
            $alias = $t[$j + 1]['v'];
            $j += 2;
        } elseif (in_array($t[$j]['k'] ?? '', ['word', 'name'], true) && !in_array($t[$j]['u'], $clause, true)) {
            $alias = $t[$j]['v'];
            $j++;
        }
        $next = $t[$j] ?? null;
        $joins = $next !== null && (self::isPunct($next, ',') || self::isWord($next, self::JOIN_WORDS));
        if ($joins && $known) {
            return ['table' => $table, 'alias' => $alias, 'next' => $j, 'refused' => 'multi-table ' . $verb];
        }
        // A known table followed by nothing or by a hole is a statement whose
        // rest comes later (`.=`) or at runtime (`UPDATE t " . $set . " WHERE`).
        $rest = $next === null || $next['k'] === 'hole';
        $statement = $verb === 'DELETE'
            ? $known && ($rest || self::isWord($next, ['WHERE', 'ORDER', 'LIMIT']))
            : self::isWord($next, ['SET']) || ($known && $rest);
        if (!$statement) {
            return null;
        }
        if (!$known) {
            return ['table' => $table, 'alias' => $alias, 'next' => $j, 'refused' => 'table not in the schema'];
        }

        return ['table' => $table, 'alias' => $alias, 'next' => $j];
    }

    /**
     * @param array{k: string, v: string, u: string}|null $tok
     * @param list<string> $words upper case
     */
    private static function isWord(?array $tok, array $words): bool
    {
        return $tok !== null && $tok['k'] === 'word' && in_array($tok['u'], $words, true);
    }

    /** @param array{k: string, v: string, u: string} $tok */
    private static function isTable(array $tok): bool
    {
        return in_array($tok['k'], ['word', 'name'], true) && isset(self::schemaTables()[strtolower($tok['v'])]);
    }

    /** @param array{k: string, v: string, u: string} $tok */
    private static function isPunct(array $tok, string $char): bool
    {
        return $tok['k'] === 'punct' && $tok['v'] === $char;
    }

    /**
     * The index of the statement's own WHERE (outside every parenthesis),
     * from $from; null when it has none.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     */
    private static function whereAt(array $t, int $from): ?int
    {
        $depth = 0;
        foreach ($t as $i => $tok) {
            if (self::isPunct($tok, '(')) {
                $depth++;
            } elseif (self::isPunct($tok, ')')) {
                $depth--;
            } elseif ($i >= $from && $depth === 0 && self::isWord($tok, ['WHERE'])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * A statement's text and holes: its chain, and when that is assigned to
     * a variable, every `.=` onto the variable after it, up to the first
     * other use of the variable (the call that runs it, or a reassignment)
     * or the next function. An assignment of an INSERT or REPLACE is passed
     * over: it is the same builder's other branch.
     *
     * @param list<\PhpToken> $tokens
     * @param array<int, array{line: int, text: string, holes: list<string>, start: int, end: int}> $byStart
     * @param array{line: int, text: string, holes: list<string>, start: int, end: int} $chain
     * @return array{0: string, 1: list<string>}
     */
    private static function assembled(array $tokens, array $byStart, array $chain): array
    {
        $text = $chain['text'];
        $holes = $chain['holes'];
        $eq = self::prevSignificant($tokens, $chain['start'] - 1);
        $var = $eq === null ? null : self::prevSignificant($tokens, $eq - 1);
        $before = $var === null ? null : self::prevSignificant($tokens, $var - 1);
        $assigned = $eq !== null && $tokens[$eq]->text === '=' && $var !== null && $tokens[$var]->id === T_VARIABLE
            && ($before === null || $tokens[$before]->id !== T_DOUBLE_COLON);
        if (!$assigned) {
            return [$text, $holes];
        }
        $name = $tokens[(int) $var]->text;
        $n = count($tokens);
        for ($k = $chain['end'] + 1; $k < $n; $k++) {
            $tok = $tokens[$k];
            if ($tok->id === T_FUNCTION || $tok->id === T_FN) {
                break;
            }
            if ($tok->id !== T_VARIABLE || $tok->text !== $name) {
                continue;
            }
            $op = self::nextSignificant($tokens, $k + 1);
            $value = $op === null ? null : self::nextSignificant($tokens, $op + 1);
            $operator = $op === null ? '' : $tokens[$op]->text;
            if ($operator === '.=') {
                if ($value !== null && isset($byStart[$value])) {
                    SqlJoinScan::append($text, $holes, $byStart[$value]);
                    $k = $byStart[$value]['end'];
                    continue;
                }
                SqlJoinScan::append($text, $holes, '.= at line ' . $tok->line);
                $k = (int) $op;
                continue;
            }
            if ($operator === '=' && $value !== null && isset($byStart[$value])) {
                $first = SqlJoinScan::sqlTokens($byStart[$value]['text'], $byStart[$value]['holes'])[0]['u'] ?? '';
                if ($first === 'INSERT' || $first === 'REPLACE') {
                    $k = $byStart[$value]['end'];
                    continue;
                }
            }
            break;
        }

        return [$text, $holes];
    }

    /**
     * Why a statement's WHERE does not tie its row to the account, as
     * "kind | table | condition"; null when it does.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @param array{table: string, alias: string, next: int, refused?: string} $target
     * @param list<string> $holes
     */
    private static function finding(array $t, array $target, array $holes): ?string
    {
        $table = $target['table'];
        if (isset($target['refused'])) {
            return $target['refused'] . ' | ' . $table . ' | ' . self::render(array_slice($t, 0, 12), $holes);
        }
        $where = self::whereAt($t, $target['next']);
        if ($where === null) {
            return 'no WHERE read | ' . $table . ' | ' . self::render($t, $holes);
        }
        $condition = [];
        $depth = 0;
        for ($i = $where + 1, $n = count($t); $i < $n; $i++) {
            $tok = $t[$i];
            if (self::isPunct($tok, '(')) {
                $depth++;
            } elseif (self::isPunct($tok, ')')) {
                $depth--;
            }
            if ($depth === 0 && self::isWord($tok, self::STATEMENT_ENDS)) {
                break;
            }
            $condition[] = $tok;
        }
        $detail = self::render($condition, $holes);

        $conjuncts = [[]];
        $depth = 0;
        $between = false;
        foreach ($condition as $tok) {
            if (self::isPunct($tok, '(')) {
                $depth++;
            } elseif (self::isPunct($tok, ')')) {
                $depth--;
            }
            $or = self::isWord($tok, ['OR', 'XOR']) || ($tok['k'] === 'op' && $tok['v'] === '||');
            if ($depth === 0 && $or) {
                return 'OR in the WHERE | ' . $table . ' | ' . $detail;
            }
            if ($depth === 0 && self::isWord($tok, ['BETWEEN'])) {
                $between = true;
            }
            $and = self::isWord($tok, ['AND']) || ($tok['k'] === 'op' && $tok['v'] === '&&');
            if ($depth === 0 && $and) {
                if ($between) {
                    $between = false;
                } else {
                    $conjuncts[] = [];
                    continue;
                }
            }
            $conjuncts[count($conjuncts) - 1][] = $tok;
        }
        foreach ($conjuncts as $conjunct) {
            if (self::isTie(self::unwrap($conjunct), $table, $target['alias'])) {
                return null;
            }
        }
        foreach ($condition as $tok) {
            if ($tok['k'] === 'hole' && SqlJoinScan::namesAClause($tok['v'])) {
                return 'WHERE built at runtime | ' . $table . ' | ' . $detail;
            }
        }
        $kind = isset(AccountScopedJoinTest::accountTables()[$table]) ? 'no user_id tie' : self::THROUGH;

        return $kind . ' | ' . $table . ' | ' . $detail;
    }

    /**
     * `[t.]user_id = <the account>`, either way round.
     *
     * @param list<array{k: string, v: string, u: string}> $c
     */
    private static function isTie(array $c, string $table, string $alias): bool
    {
        $eq = null;
        foreach ($c as $k => $tok) {
            if ($tok['k'] === 'op' && $tok['v'] === '=') {
                if ($eq !== null) {
                    return false;
                }
                $eq = $k;
            }
        }
        if ($eq === null) {
            return false;
        }
        $left = array_slice($c, 0, $eq);
        $right = array_slice($c, $eq + 1);
        foreach ([[$left, $right], [$right, $left]] as [$column, $value]) {
            if (self::isOwnUserId($column, $table, $alias) && self::isAccount($value)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{k: string, v: string, u: string}> $side */
    private static function isOwnUserId(array $side, string $table, string $alias): bool
    {
        $named = static fn (array $tok): bool => in_array($tok['k'], ['word', 'name'], true);
        if (count($side) === 1) {
            return $named($side[0]) && strtolower($side[0]['v']) === 'user_id';
        }
        if (count($side) === 3 && $named($side[0]) && $side[1]['v'] === '.' && $named($side[2])) {
            $qualifier = strtolower($side[0]['v']);

            return ($qualifier === $table || ($alias !== '' && $qualifier === strtolower($alias)))
                && strtolower($side[2]['v']) === 'user_id';
        }

        return false;
    }

    /** @param list<array{k: string, v: string, u: string}> $side */
    private static function isAccount(array $side): bool
    {
        if (count($side) !== 1) {
            return false;
        }
        $tok = $side[0];
        if ($tok['k'] === 'param') {
            return true;
        }
        if (in_array($tok['k'], ['word', 'str'], true) && preg_match('/^[1-9][0-9]*$/', $tok['v']) === 1) {
            return true;
        }
        $source = $tok['k'] === 'hole' ? $tok['v'] : ($tok['k'] === 'str' ? $tok['u'] : '');
        $bare = (string) preg_replace('/^\((?:int|string)\)\s*/i', '', trim($source, '{} '));
        $requested = preg_match('/^\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES|ENV)\b/', $bare) === 1;

        return $source !== '' && !$requested && SqlJoinScan::namesAUserId($source);
    }

    /**
     * @param list<array{k: string, v: string, u: string}> $c
     * @return list<array{k: string, v: string, u: string}>
     */
    private static function unwrap(array $c): array
    {
        while (count($c) >= 2 && self::isPunct($c[0], '(') && self::isPunct($c[count($c) - 1], ')')) {
            $depth = 0;
            foreach ($c as $i => $tok) {
                if (self::isPunct($tok, '(')) {
                    $depth++;
                } elseif (self::isPunct($tok, ')')) {
                    $depth--;
                    if ($depth === 0 && $i !== count($c) - 1) {
                        return $c;
                    }
                }
            }
            $c = array_slice($c, 1, -1);
        }

        return $c;
    }

    /**
     * @param list<array{k: string, v: string, u: string}> $t
     * @param list<string> $holes
     */
    private static function render(array $t, array $holes): string
    {
        $inString = static fn (string $v): string => (string) preg_replace_callback(
            "/\x01(\\d+)\x02/",
            static fn (array $m): string => '{' . ($holes[(int) $m[1]] ?? '') . '}',
            $v
        );
        $out = '';
        foreach ($t as $tok) {
            $out .= match ($tok['k']) {
                'hole' => '{' . $tok['v'] . '}',
                'str' => "'" . $inString($tok['v']) . "'",
                'name' => '`' . $tok['v'] . '`',
                default => $tok['v'],
            } . ' ';
        }
        $out = str_replace(' . ', '.', $out);

        return trim(mb_strimwidth($out, 0, 200, '…'));
    }

    /** @param list<\PhpToken> $tokens */
    private static function prevSignificant(array $tokens, int $i): ?int
    {
        for (; $i >= 0; $i--) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function nextSignificant(array $tokens, int $i): ?int
    {
        for ($n = count($tokens); $i < $n; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    private static function inScope(string $path): bool
    {
        foreach (self::SCOPE as $dir) {
            if (str_starts_with($path, $dir)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<array{line: int, key: string}>> repo-relative file => its findings */
    public static function treeFindings(): array
    {
        if (self::$tree !== null) {
            return self::$tree;
        }
        $out = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $writes = stripos($source, 'update') !== false || stripos($source, 'delete') !== false;
            if (!$writes || !self::inScope($path)) {
                continue;
            }
            $seen = [];
            foreach (self::scan($source) as $f) {
                $n = $seen[$f['key']] = ($seen[$f['key']] ?? 0) + 1;
                $out[$path][] = ['line' => $f['line'], 'key' => $f['key'] . ($n > 1 ? " #$n" : '')];
            }
        }

        return self::$tree = $out;
    }

    public function testEveryWriteOfARowNamedByIdNamesItsAccount(): void
    {
        $unexpected = [];
        foreach (self::treeFindings() as $file => $findings) {
            foreach ($findings as $f) {
                [$kind, $rest] = explode(' | ', $f['key'], 2);
                if ($kind !== self::THROUGH || !isset(self::THROUGH_PARENT[$file][$rest])) {
                    $unexpected[] = "  $file:{$f['line']}  {$f['key']}";
                }
            }
        }
        self::assertSame([], $unexpected, sprintf(
            "These writes name a row without tying it to the signed-in account:\n%s\n"
            . "A request can name any account's id, so add `AND user_id = <the account>` to the WHERE (`?`,\n"
            . "bound to the session's user id), and never set user_id from the session on an UPDATE. A table\n"
            . "owned through its parent has no user_id: check the parent is the account's before the write, on\n"
            . 'every path, and list the write in THROUGH_PARENT with that check.',
            implode("\n", $unexpected)
        ));
    }

    public function testEveryListedWriteStillExists(): void
    {
        $tree = self::treeFindings();
        $stale = [];
        $listed = 0;
        foreach (self::THROUGH_PARENT as $file => $keys) {
            $present = array_column($tree[$file] ?? [], 'key');
            foreach (array_keys($keys) as $key) {
                $listed++;
                if (!in_array(self::THROUGH . ' | ' . $key, $present, true)) {
                    $stale[] = "  THROUGH_PARENT: $file  $key";
                }
            }
        }
        $message = "These entries match no write any more — remove them, and lower CEILING:\n" . implode("\n", $stale);
        self::assertSame([], $stale, $message);
        self::assertLessThanOrEqual(self::CEILING, $listed, 'THROUGH_PARENT only shrinks: tie the new write instead');
    }

    public function testTheScopeIsServedCode(): void
    {
        $paths = array_keys(SourceScan::phpFiles());
        foreach (self::SCOPE as $dir) {
            $found = array_filter($paths, static fn (string $path): bool => str_starts_with($path, $dir));
            self::assertNotSame([], $found, "$dir holds no PHP: the scope names a directory that moved");
        }
        self::assertTrue(self::inScope('tracking202/setup/ppc_accounts.php'));
    }

    /**
     * @return array<string, array{string, list<string>}> PHP => the kinds it
     *         must be reported as ([] for a write that names its account)
     */
    public static function spellings(): array
    {
        $untied = ['no user_id tie'];
        $or = ['OR in the WHERE'];
        $noWhere = ['no WHERE read'];

        return [
            'tied, interpolated' => [<<<'PHP'
                $db->query("UPDATE 202_ppc_networks SET ppc_network_name = 'x'
                    WHERE ppc_network_id = '$id' AND user_id = '{$mysql['user_id']}'");
                PHP, []],
            'by id alone' => [<<<'PHP'
                $db->query("UPDATE 202_ppc_networks SET ppc_network_name = 'x' WHERE ppc_network_id = '$id'");
                PHP, $untied],
            'prepared, tied' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks SET ppc_network_name = ?
                    WHERE ppc_network_id = ? AND user_id = ?');
                PHP, []],
            'prepared, by id alone' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks SET ppc_network_name = ? WHERE ppc_network_id = ?');
                PHP, $untied],
            'tied through the alias' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks AS pn SET pn.ppc_network_name = ?
                    WHERE pn.ppc_network_id = ? AND pn.user_id = ?');
                PHP, []],
            'tied through the table name' => [<<<'PHP'
                $c->prepareWrite('UPDATE `202_ppc_networks` SET ppc_network_name = ?
                    WHERE `202_ppc_networks`.`user_id` = ? AND ppc_network_id = ?');
                PHP, []],
            'another alias\'s user_id' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks pn SET ppc_network_name = ?
                    WHERE pn.ppc_network_id = ? AND other.user_id = ?');
                PHP, $untied],
            'the tie written the other way round' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM 202_trackers WHERE tracker_id = ? AND ? = user_id');
                PHP, []],
            'user_id in the SET only' => [<<<'PHP'
                $db->query("UPDATE 202_ppc_networks SET user_id = '{$mysql['user_id']}'
                    WHERE ppc_network_id = '$id'");
                PHP, $untied],
            'an OR beside the tie' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers SET click_cpc = ?
                    WHERE tracker_id = ? AND user_id = ? OR 1 = 1');
                PHP, $or],
            'an OR around the tie' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers SET click_cpc = ?
                    WHERE (tracker_id = ? AND user_id = ?) OR tracker_id = ?');
                PHP, $or],
            'parenthesized conjuncts' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers SET click_cpc = ? WHERE (tracker_id = ?) AND (user_id = ?)');
                PHP, []],
            'a commented-out tie' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_trackers SET click_cpc = ? WHERE tracker_id = ? /* AND user_id = ? */');
                PHP, $untied],
            'a tie after a line comment' => [<<<'PHP'
                $c->prepareWrite("UPDATE 202_trackers SET click_cpc = ? WHERE tracker_id = ? -- AND user_id = ?\n");
                PHP, $untied],
            'user_id compared with a posted value' => [<<<'PHP'
                $db->query("DELETE FROM 202_trackers
                    WHERE tracker_id = '$id' AND user_id = '{$_POST['user_id']}'");
                PHP, $untied],
            'the session\'s user id' => [<<<'PHP'
                $db->query("UPDATE 202_users SET user_email = 'x'
                    WHERE user_id = '" . (int) $_SESSION['user_id'] . "'");
                PHP, []],
            'user_id compared with a value named otherwise' => [<<<'PHP'
                $db->query("DELETE FROM 202_trackers WHERE tracker_id = '$id' AND user_id = '{$owner}'");
                PHP, $untied],
            'the install\'s own row' => [<<<'PHP'
                $db->query("UPDATE 202_users_pref SET auto_cron = '1' WHERE user_id = '1'");
                PHP, []],
            'a cast user id' => [<<<'PHP'
                $db->query('UPDATE 202_trackers SET click_cpc = 1 '
                    . 'WHERE tracker_id = ' . $id . ' AND user_id = ' . (int) $userId);
                PHP, []],
            'a heredoc' => [<<<'PHP'
                $db->query(<<<SQL
                    UPDATE 202_trackers SET click_cpc = 1
                    WHERE tracker_id = {$id}
                    SQL);
                PHP, $untied],
            'lower case' => [<<<'PHP'
                $c->prepareWrite('update 202_trackers set click_cpc = ? where tracker_id = ?');
                PHP, $untied],
            'sprintf, tied' => [<<<'PHP'
                $db->query(sprintf(
                    "UPDATE 202_trackers SET click_cpc = 1 WHERE tracker_id = %d AND user_id = %d",
                    $id,
                    $userId
                ));
                PHP, []],
            'sprintf, untied' => [<<<'PHP'
                $db->query(sprintf(
                    "UPDATE 202_trackers SET click_cpc = 1 WHERE tracker_id = %d AND user_id = %d",
                    $id,
                    $other
                ));
                PHP, $untied],
            'BETWEEN beside the tie' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM 202_trackers WHERE tracker_id BETWEEN ? AND ? AND user_id = ?');
                PHP, []],
            'the tie only in a subquery' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM 202_trackers
                    WHERE tracker_id IN (SELECT tracker_id FROM 202_trackers WHERE user_id = ?)');
                PHP, $untied],
            'a DELETE by id alone' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM 202_trackers WHERE tracker_id = ?');
                PHP, $untied],
            'a table owned through its parent' => [<<<'PHP'
                $c->prepareWrite('DELETE FROM 202_rotator_rules WHERE rotator_id = ?');
                PHP, [self::THROUGH]],
            'a table built at runtime' => [<<<'PHP'
                $db->query("UPDATE {$table} SET x = 1 WHERE id = ? AND user_id = ?");
                PHP, ['table built at runtime']],
            'a table not in the schema' => [<<<'PHP'
                $db->query("UPDATE 202_no_such_table SET x = 1 WHERE id = ? AND user_id = ?");
                PHP, ['table not in the schema']],
            'a multi-table UPDATE' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks pn
                    JOIN 202_ppc_accounts pa ON (pa.ppc_network_id = pn.ppc_network_id)
                    SET pn.ppc_network_name = ? WHERE pa.ppc_account_id = ?');
                PHP, ['multi-table UPDATE']],
            'a comma-joined UPDATE' => [<<<'PHP'
                $c->prepareWrite('UPDATE 202_ppc_networks, 202_ppc_accounts SET ppc_network_name = ?
                    WHERE ppc_account_id = ?');
                PHP, ['multi-table UPDATE']],
            'a multi-table DELETE' => [<<<'PHP'
                $c->prepareWrite('DELETE t FROM 202_trackers t WHERE t.tracker_id = ?');
                PHP, ['multi-table DELETE']],
            'a SET list built at runtime' => [<<<'PHP'
                $db->query("UPDATE 202_trackers " . $set . " WHERE tracker_id = '$id'");
                PHP, $untied],
            'a SET list built at runtime, tied' => [<<<'PHP'
                $db->query("UPDATE 202_trackers {$set} WHERE tracker_id = ? AND user_id = ?");
                PHP, []],
            'no WHERE' => [<<<'PHP'
                $db->query("UPDATE 202_trackers SET click_cpc = '1'");
                PHP, $noWhere],
            'a WHERE built at runtime' => [<<<'PHP'
                $db->query("UPDATE 202_trackers SET click_cpc = 1 WHERE tracker_id = ? {$where}");
                PHP, ['WHERE built at runtime']],
            // The shape the traffic source rename shipped in.
            'finished by appends, the INSERT twin passed over' => [<<<'PHP'
                if ($editing) {
                    $sql = " UPDATE 202_ppc_networks SET";
                } else {
                    $sql = "INSERT INTO `202_ppc_networks` SET";
                }
                $sql .= " `user_id`='" . $mysql['user_id'] . "', `ppc_network_name`='" . $name . "'";
                if ($editing) {
                    $sql .= "WHERE ppc_network_id='" . $mysql['ppc_network_id'] . "'";
                }
                _mysqli_query($sql);
                PHP, $untied],
            'finished by appends, tied' => [<<<'PHP'
                if ($editing) {
                    $sql = 'UPDATE `202_aff_networks` SET';
                } else {
                    $sql = 'INSERT INTO `202_aff_networks` SET';
                }
                $sql .= " `aff_network_name`='" . $name . "'";
                if ($editing) {
                    $sql .= "WHERE `aff_network_id`='" . $id . "' AND `user_id`='" . $mysql['user_id'] . "'";
                }
                $db->query($sql);
                PHP, []],
            'an append the scan cannot read' => [<<<'PHP'
                $sql = 'UPDATE 202_trackers SET click_cpc = 1';
                $sql .= $where;
                $db->query($sql);
                PHP, $noWhere],
            'reassigned before its WHERE' => [<<<'PHP'
                $sql = 'UPDATE 202_trackers SET click_cpc = 1';
                $sql = 'SELECT 1 FROM 202_trackers';
                $sql .= ' WHERE user_id = ?';
                PHP, $noWhere],
            'run before its WHERE is appended' => [<<<'PHP'
                $sql = 'UPDATE 202_trackers SET click_cpc = 1';
                $db->query($sql);
                $sql .= ' WHERE tracker_id = 1 AND user_id = ?';
                PHP, $noWhere],
            'a label that is not SQL' => [<<<'PHP'
                echo 'Update tracking link';
                echo "Delete export {$id} and its file?";
                echo 'Delete a rule from a rotator';
                record_mysql_error($db, 'UPDATE 202_rotators (redirector ' . $id . ')');
                PHP, []],
            'a lock that is not a write' => [<<<'PHP'
                $c->prepareWrite('SELECT id FROM 202_rotators WHERE id = ? FOR UPDATE');
                PHP, []],
        ];
    }

    /**
     * @dataProvider spellings
     * @param list<string> $kinds
     */
    public function testTheScannerReadsEverySpellingItClaims(string $php, array $kinds): void
    {
        $found = array_map(
            static fn (array $f): string => explode(' | ', $f['key'])[0],
            self::scan("<?php\n" . $php . "\n")
        );
        self::assertSame($kinds, $found, $php);
    }
}
