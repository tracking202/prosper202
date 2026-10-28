<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Reads the SQL a PHP file writes and reports every place it reads an
 * account-owned table from another row: a JOIN onto it, a table after a
 * comma in a FROM or UPDATE list, and a subquery that selects from it. For
 * AccountScopedJoinTest (see that class for the invariant).
 *
 * PHP side. A "chain" is one string expression: string literals, double
 * quoted strings, heredocs and nowdocs joined by the `.` operator. What the
 * chain cannot read becomes a hole: an interpolated variable, a constant, a
 * call, anything concatenated that is not a literal. A hole is never read as
 * SQL text — it is an opaque token whose PHP source is kept, so a table
 * name or an ON clause built at runtime is refused by name rather than read
 * as "no such join" (CLAUDE.md #20). A chain ends at anything that is not
 * `.`, so `$sql .= '...'` starts a new chain: a JOIN whose ON clause is
 * finished by a later statement is read as unfinished, which fails loudly.
 *
 * SQL side. Each chain is tokenized (words, `backticked` names, quoted
 * strings, `?`, holes, operators; `/* *\/`, `-- ` and `#` comments are
 * dropped, so a commented-out condition is not credited) and walked for:
 *
 *  - JOIN / STRAIGHT_JOIN (any of INNER, LEFT, RIGHT, CROSS, OUTER before
 *    it): the table, optional `AS` and alias, index hints, then ON or USING;
 *  - FROM and UPDATE lists: a table after a top-level comma (a comma join);
 *  - a FROM whose first table is account-owned, inside parentheses (a
 *    subquery, derived table, IN/EXISTS select): its WHERE.
 *
 * A condition credits a tie only when it is a top-level conjunct (no OR,
 * XOR or `||` at the condition's top level) of the exact shape
 * `<alias>.user_id = <value>` (either way round), where <value> is another
 * alias's `user_id`, a `?`, or a hole in value position whose PHP source
 * names a user id. In a subquery's WHERE the column may be unqualified
 * (it resolves to the subquery's own table first). USING (... user_id ...)
 * is a tie. Everything else — a hole anywhere else in the condition, a
 * NATURAL join, a missing ON — is reported as what it is.
 */
final class SqlJoinScan
{
    private const HOLE_OPEN = "\x01";
    private const HOLE_CLOSE = "\x02";

    /** Words that end an alias position or a condition. */
    private const CLAUSE_WORDS = [
        'JOIN', 'STRAIGHT_JOIN', 'LEFT', 'RIGHT', 'INNER', 'CROSS', 'NATURAL', 'OUTER', 'FULL',
        'WHERE', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION', 'WINDOW', 'SET', 'FOR', 'LOCK',
        'INTO', 'RETURNING', 'ON', 'USING', 'USE', 'FORCE', 'IGNORE', 'VALUES', 'SELECT',
        'PARTITION', 'EXCEPT', 'INTERSECT', 'OFFSET',
    ];

    /** Words that end a condition (an ON clause or a subquery's WHERE). */
    private const CONDITION_END_WORDS = [
        'JOIN', 'STRAIGHT_JOIN', 'LEFT', 'RIGHT', 'INNER', 'CROSS', 'NATURAL', 'FULL',
        'WHERE', 'GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION', 'WINDOW', 'SET', 'FOR', 'LOCK',
        'INTO', 'RETURNING', 'ON', 'EXCEPT', 'INTERSECT',
    ];

    private const COMPARISON = ['=', '<', '>', '<=', '>=', '<>', '!=', '<=>'];

    // ------------------------------------------------------------------
    // PHP: string chains
    // ------------------------------------------------------------------

    /**
     * Every string expression in a PHP source.
     *
     * @return list<array{line: int, text: string, holes: list<string>}>
     */
    public static function chains(string $source): array
    {
        $tokens = \PhpToken::tokenize($source);
        $n = count($tokens);
        $consumed = [];
        $chains = [];

        for ($i = 0; $i < $n; $i++) {
            if (isset($consumed[$i]) || !self::startsString($tokens[$i])) {
                continue;
            }
            $text = '';
            $holes = [];
            $line = $tokens[$i]->line;
            $prev = self::prevSignificant($tokens, $i - 1);
            if ($prev !== null && $tokens[$prev]->text === '.') {
                // `$x . '...'`: what came before is unknown text.
                self::addHole($text, $holes, self::sourceBack($tokens, $prev - 1));
            }
            $at = $i;
            while (true) {
                if (self::startsString($tokens[$at])) {
                    $end = self::readString($tokens, $at, $text, $holes);
                    for ($k = $at; $k <= $end; $k++) {
                        $consumed[$k] = true;
                    }
                } else {
                    $end = self::readOperand($tokens, $at);
                    self::addHole($text, $holes, self::sourceOf($tokens, $at, $end));
                }
                $dot = self::nextSignificant($tokens, $end + 1);
                if ($dot === null || $tokens[$dot]->text !== '.') {
                    break;
                }
                $next = self::nextSignificant($tokens, $dot + 1);
                if ($next === null) {
                    break;
                }
                $at = $next;
            }
            $after = $dot === null ? null : $tokens[$dot]->text;
            $before = $prev === null ? null : $tokens[$prev]->text;
            if ($holes === [] && preg_match('/^\w+$/', $text) === 1
                && ($after === '=>' || ($before === '[' && $after === ']'))) {
                continue; // a one-word array key or index (`'join' => …`, `$bd['join']`) is not SQL
            }
            $call = $before === '(' ? self::prevSignificant($tokens, (int) $prev - 1) : null;
            if ($call !== null && $tokens[$call]->id === T_STRING && strtolower($tokens[$call]->text) === 'sprintf'
                && !in_array($tokens[self::prevSignificant($tokens, $call - 1) ?? 0]->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                // sprintf()'s format: each conversion is a hole holding the
                // argument it takes, so `user_id = %2$d` reads as the source
                // of the second argument.
                $text = self::sprintfHoles($text, $holes, self::callArguments($tokens, $dot));
            }
            $chains[] = ['line' => $line, 'text' => $text, 'holes' => $holes];
        }

        return $chains;
    }

    /**
     * The source of each argument after a call's first, from the token
     * after that first argument (a `,` or the closing `)`).
     *
     * @param list<\PhpToken> $tokens
     * @return list<string>
     */
    private static function callArguments(array $tokens, ?int $at): array
    {
        $args = [];
        if ($at === null || $tokens[$at]->text !== ',') {
            return $args;
        }
        $start = $at + 1;
        $depth = 0;
        for ($j = $start, $n = count($tokens); $j < $n; $j++) {
            $text = $tokens[$j]->text;
            if (in_array($text, ['(', '[', '{'], true) || in_array($tokens[$j]->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    $args[] = self::sourceOf($tokens, $start, $j - 1);

                    return $args;
                }
                $depth--;
            } elseif ($text === ',' && $depth === 0) {
                $args[] = self::sourceOf($tokens, $start, $j - 1);
                $start = $j + 1;
            }
        }

        return $args;
    }

    /**
     * A sprintf() format with each conversion replaced by a hole holding
     * its argument's source (`%%` is a literal percent sign).
     *
     * @param list<string> $holes
     * @param list<string> $args
     */
    private static function sprintfHoles(string $text, array &$holes, array $args): string
    {
        $next = 0;

        return (string) preg_replace_callback(
            '/%(?:(\d+)\$)?[-+ 0]*\d*(?:\.\d+)?([bcdeEfFgGosuxX%])/',
            static function (array $m) use (&$holes, &$next, $args): string {
                if ($m[2] === '%') {
                    return '%';
                }
                $index = $m[1] !== '' ? (int) $m[1] - 1 : $next++;
                $placeholder = self::HOLE_OPEN . count($holes) . self::HOLE_CLOSE;
                $holes[] = $args[$index] ?? $m[0];

                return $placeholder;
            },
            $text
        );
    }

    private static function startsString(\PhpToken $t): bool
    {
        // A bare `"` is the open quote only as PHP's own token: inline HTML
        // between two PHP blocks (the closing quote of an attribute whose
        // value was echoed) can be a lone `"` too.
        return $t->id === T_CONSTANT_ENCAPSED_STRING || $t->id === ord('"') || $t->id === T_START_HEREDOC;
    }

    /**
     * Appends one string literal (single or double quoted, heredoc, nowdoc)
     * to $text, holes for its interpolations. Returns its last token index.
     *
     * @param list<\PhpToken> $tokens
     * @param list<string> $holes
     */
    private static function readString(array $tokens, int $i, string &$text, array &$holes): int
    {
        $t = $tokens[$i];
        if ($t->id === T_CONSTANT_ENCAPSED_STRING) {
            $body = substr($t->text, 1, -1);
            $text .= $t->text[0] === "'"
                ? str_replace(["\\'", '\\\\'], ["'", '\\'], $body)
                : stripcslashes($body);

            return $i;
        }
        $nowdoc = $t->id === T_START_HEREDOC && str_contains($t->text, "'");
        $close = $t->id === T_START_HEREDOC ? T_END_HEREDOC : null;
        $n = count($tokens);
        for ($j = $i + 1; $j < $n; $j++) {
            $u = $tokens[$j];
            if (($close !== null && $u->id === $close) || ($close === null && $u->id === ord('"'))) {
                return $j;
            }
            if ($u->id === T_ENCAPSED_AND_WHITESPACE) {
                $text .= $nowdoc ? $u->text : stripcslashes($u->text);
                continue;
            }
            if ($u->id === T_CURLY_OPEN || $u->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $end = self::matching($tokens, $j, '}');
                self::addHole($text, $holes, self::sourceOf($tokens, $j, $end));
                $j = $end;
                continue;
            }
            if ($u->id === T_VARIABLE) {
                $end = $j;
                $after = $tokens[$j + 1] ?? null;
                if ($after !== null && $after->text === '[') {
                    $end = self::matching($tokens, $j + 1, ']');
                } elseif ($after !== null && ($after->id === T_OBJECT_OPERATOR || $after->id === T_NULLSAFE_OBJECT_OPERATOR)) {
                    $end = $j + 2;
                }
                self::addHole($text, $holes, self::sourceOf($tokens, $j, $end));
                $j = $end;
                continue;
            }
            throw new RuntimeException("Unexpected token {$u->getTokenName()} in a string on line {$u->line}");
        }
        throw new RuntimeException("Unterminated string starting on line {$t->line}");
    }

    /**
     * The extent of a non-literal operand of `.`: a variable, constant,
     * call or cast with its postfix chain, or a parenthesized expression.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function readOperand(array $tokens, int $i): int
    {
        $n = count($tokens);
        $j = $i;
        // Prefixes.
        while ($j < $n && (in_array($tokens[$j]->id, [T_INT_CAST, T_STRING_CAST, T_DOUBLE_CAST, T_BOOL_CAST, T_ARRAY_CAST, T_OBJECT_CAST, T_NEW], true)
            || in_array($tokens[$j]->text, ['!', '-', '+', '@', '&', '$'], true) || $tokens[$j]->isIgnorable())) {
            $j++;
        }
        if ($j >= $n) {
            return $n - 1;
        }
        $t = $tokens[$j];
        if (in_array($t->text, ['(', '['], true)) {
            $end = self::matching($tokens, $j, $t->text === '(' ? ')' : ']');
        } elseif ($t->id === T_MATCH) {
            $paren = self::nextSignificant($tokens, $j + 1);
            $brace = self::nextSignificant($tokens, self::matching($tokens, (int) $paren, ')') + 1);
            $end = self::matching($tokens, (int) $brace, '}');
        } else {
            $end = $j;
        }
        // Postfixes.
        while (true) {
            $k = self::nextSignificant($tokens, $end + 1);
            if ($k === null) {
                return $end;
            }
            $u = $tokens[$k];
            if ($u->text === '[' || $u->text === '(' || $u->text === '{') {
                $end = self::matching($tokens, $k, ['[' => ']', '(' => ')', '{' => '}'][$u->text]);
            } elseif (in_array($u->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                $m = self::nextSignificant($tokens, $k + 1);
                if ($m === null) {
                    return $k;
                }
                $end = $tokens[$m]->text === '{' ? self::matching($tokens, $m, '}') : $m;
            } elseif ($u->id === T_INC || $u->id === T_DEC) {
                $end = $k;
            } else {
                return $end;
            }
        }
    }

    /** @param list<\PhpToken> $tokens */
    private static function matching(array $tokens, int $open, string $close): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($j = $open; $j < $n; $j++) {
            $text = $tokens[$j]->text;
            if (in_array($text, ['(', '[', '{'], true) || in_array($tokens[$j]->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    if ($text !== $close) {
                        throw new RuntimeException("Mismatched $text on line {$tokens[$j]->line}");
                    }

                    return $j;
                }
            }
        }
        throw new RuntimeException("Unbalanced bracket from line {$tokens[$open]->line}");
    }

    /** @param list<\PhpToken> $tokens */
    private static function nextSignificant(array $tokens, int $from): ?int
    {
        for ($j = $from, $n = count($tokens); $j < $n; $j++) {
            if (!$tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function prevSignificant(array $tokens, int $from): ?int
    {
        for ($j = $from; $j >= 0; $j--) {
            if (!$tokens[$j]->isIgnorable()) {
                return $j;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function sourceOf(array $tokens, int $from, int $to): string
    {
        $out = '';
        for ($j = $from; $j <= $to; $j++) {
            $out .= $tokens[$j]->text;
        }

        return trim((string) preg_replace('/\s+/', ' ', $out));
    }

    /** A few tokens of what precedes a `.`, for messages. @param list<\PhpToken> $tokens */
    private static function sourceBack(array $tokens, int $to): string
    {
        $from = max(0, $to - 3);

        return $to < 0 ? '' : self::sourceOf($tokens, $from, $to);
    }

    /** @param list<string> $holes */
    private static function addHole(string &$text, array &$holes, string $source): void
    {
        $text .= self::HOLE_OPEN . count($holes) . self::HOLE_CLOSE;
        $holes[] = $source;
    }

    // ------------------------------------------------------------------
    // SQL: tokens
    // ------------------------------------------------------------------

    /**
     * @param list<string> $holes
     * @return list<array{k: string, v: string, u: string}> k: word|name|str|param|hole|op|punct;
     *         v: the text (a name unquoted); u: upper-cased word, or for a
     *         str/hole the PHP source of the one hole it is (else '')
     */
    public static function sqlTokens(string $text, array $holes): array
    {
        $out = [];
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            $c = $text[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === self::HOLE_OPEN) {
                $end = strpos($text, self::HOLE_CLOSE, $i);
                $index = (int) substr($text, $i + 1, (int) $end - $i - 1);
                $out[] = ['k' => 'hole', 'v' => $holes[$index] ?? '', 'u' => $holes[$index] ?? '', 'g' => $i === 0 || ctype_space($text[$i - 1])];
                $i = (int) $end + 1;
                continue;
            }
            if ($c === '/' && ($text[$i + 1] ?? '') === '*') {
                $end = strpos($text, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                continue;
            }
            if (($c === '-' && ($text[$i + 1] ?? '') === '-' && ctype_space($text[$i + 2] ?? "\n")) || $c === '#') {
                $end = strpos($text, "\n", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($c === "'" || $c === '"') {
                $j = $i + 1;
                $body = '';
                while ($j < $len) {
                    if ($text[$j] === '\\' && $j + 1 < $len) {
                        $body .= $text[$j + 1];
                        $j += 2;
                        continue;
                    }
                    if ($text[$j] === $c) {
                        if (($text[$j + 1] ?? '') === $c) {
                            $body .= $c;
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $body .= $text[$j];
                    $j++;
                }
                $one = preg_match('/^' . self::HOLE_OPEN . '(\d+)' . self::HOLE_CLOSE . '$/', $body, $m) === 1
                    ? ($holes[(int) $m[1]] ?? '') : '';
                $out[] = ['k' => 'str', 'v' => $body, 'u' => $one];
                $i = $j + 1;
                continue;
            }
            if ($c === '`') {
                $end = strpos($text, '`', $i + 1);
                $end = $end === false ? $len : $end;
                $name = substr($text, $i + 1, $end - $i - 1);
                if (str_contains($name, self::HOLE_OPEN)) {
                    // `$table`: a quoted name built at runtime is a hole, never a name.
                    $source = (string) preg_replace_callback(
                        '/' . self::HOLE_OPEN . '(\d+)' . self::HOLE_CLOSE . '/',
                        static fn (array $m): string => '{' . ($holes[(int) $m[1]] ?? '') . '}',
                        $name
                    );
                    $out[] = ['k' => 'hole', 'v' => $source, 'u' => $source, 'g' => $i === 0 || ctype_space($text[$i - 1])];
                } else {
                    $out[] = ['k' => 'name', 'v' => $name, 'u' => strtoupper($name)];
                }
                $i = $end + 1;
                continue;
            }
            if (preg_match('/\G[A-Za-z0-9_$]+/', $text, $m, 0, $i) === 1) {
                $out[] = ['k' => 'word', 'v' => $m[0], 'u' => strtoupper($m[0])];
                $i += strlen($m[0]);
                continue;
            }
            if ($c === '?') {
                $out[] = ['k' => 'param', 'v' => '?', 'u' => ''];
                $i++;
                continue;
            }
            if (preg_match('/\G(<=>|<=|>=|<>|!=|\|\||&&|:=)/', $text, $m, 0, $i) === 1) {
                $out[] = ['k' => 'op', 'v' => $m[0], 'u' => $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            $out[] = ['k' => in_array($c, ['=', '<', '>'], true) ? 'op' : 'punct', 'v' => $c, 'u' => $c];
            $i++;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // SQL: findings
    // ------------------------------------------------------------------

    /**
     * Every read of an account-owned table from another row in one chain,
     * and every place one may be that the scan cannot read.
     *
     * @param array<string, true> $accountTables lower-case table name => true
     * @param list<string> $holes
     * @return list<array{kind: string, table: string, detail: string}>
     *         kind: tied (passes), or a reason it does not
     */
    public static function findings(string $text, array $holes, array $accountTables): array
    {
        $t = self::sqlTokens($text, $holes);
        $n = count($t);
        $found = [];
        $depth = 0;
        $depths = [];
        foreach ($t as $i => $tok) {
            if ($tok['v'] === '(' && $tok['k'] === 'punct') {
                $depth++;
            }
            $depths[$i] = $depth;
            if ($tok['v'] === ')' && $tok['k'] === 'punct') {
                $depth--;
            }
        }

        for ($i = 0; $i < $n; $i++) {
            $tok = $t[$i];
            if ($tok['k'] !== 'word') {
                continue;
            }
            $prev = $t[$i - 1] ?? null;
            if ($prev !== null && $prev['v'] === '.') {
                continue; // a qualified name or a method (`x.join`), not a keyword
            }
            if ($tok['u'] === 'JOIN' || $tok['u'] === 'STRAIGHT_JOIN') {
                $found = [...$found, ...self::join($t, $i, $accountTables)];
            } elseif ($tok['u'] === 'FROM' || $tok['u'] === 'UPDATE') {
                $found = [...$found, ...self::tableList($t, $i, $depths, $accountTables)];
            }
        }

        return $found;
    }

    /**
     * @param list<array{k: string, v: string, u: string}> $t
     * @param array<string, true> $accountTables
     * @return list<array{kind: string, table: string, detail: string}>
     */
    private static function join(array $t, int $i, array $accountTables): array
    {
        $natural = false;
        for ($b = $i - 1; $b >= 0 && $t[$b]['k'] === 'word' && in_array($t[$b]['u'], ['LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'NATURAL', 'FULL'], true); $b--) {
            $natural = $natural || $t[$b]['u'] === 'NATURAL';
        }
        $j = $i + 1;
        $ref = self::tableRef($t, $j, $accountTables);
        if ($ref === null) {
            return [['kind' => 'no table after JOIN', 'table' => '?', 'detail' => self::excerpt($t, $i)]];
        }
        if ($ref['kind'] !== 'table') {
            return $ref['kind'] === 'derived' ? [] : [['kind' => $ref['kind'], 'table' => $ref['table'], 'detail' => self::excerpt($t, $i)]];
        }
        if (!isset($accountTables[strtolower($ref['table'])])) {
            return [];
        }
        $table = $ref['table'];
        $alias = $ref['alias'];
        $j = $ref['next'];
        if ($natural) {
            return [['kind' => 'NATURAL join', 'table' => $table, 'detail' => self::excerpt($t, $i)]];
        }
        $word = $t[$j]['u'] ?? '';
        if (($t[$j]['k'] ?? '') === 'word' && $word === 'USING') {
            $close = self::closeParen($t, $j + 1);
            $listed = array_slice($t, $j + 2, max(0, $close - $j - 2));
            if (in_array('hole', array_column($listed, 'k'), true)) {
                return [['kind' => 'USING built at runtime', 'table' => $table, 'detail' => self::excerpt($t, $i)]];
            }
            $columns = array_map(static fn(array $c): string => strtolower($c['v']), $listed);

            return [in_array('user_id', $columns, true)
                ? ['kind' => 'tied', 'table' => $table, 'detail' => 'USING (user_id)']
                : ['kind' => 'USING without user_id', 'table' => $table, 'detail' => self::excerpt($t, $i)]];
        }
        if (($t[$j]['k'] ?? '') !== 'word' || $word !== 'ON') {
            return [['kind' => 'no ON clause', 'table' => $table, 'detail' => self::excerpt($t, $i)]];
        }
        $open = false;
        $condition = self::condition($t, $j + 1, $open);
        $verdict = self::judge($condition, $table, $alias, false) + ['table' => $table];
        if ($verdict['kind'] === 'no user_id tie') {
            // The same query's WHERE may tie it instead: `JOIN t ON t.id =
            // x.t_id … WHERE t.user_id = ?` reads only the account's rows
            // (a LEFT JOIN so filtered drops the row rather than naming
            // another account's, which is no leak).
            $where = self::whereTie($t, $j + 1 + count($condition), $table, $alias);
            if ($where !== null) {
                return [$where + ['table' => $table]];
            }
        }
        if ($open) {
            // The condition runs to the end of this string expression: the
            // caller looks at what the next one appends.
            $verdict['open'] = true;
        }

        return [$verdict];
    }

    /**
     * The verdict of the WHERE of the query level a join at $j belongs to,
     * when that WHERE ties the joined alias; null when there is none, it
     * does not, or it cannot be read (it is then the ON clause's verdict
     * that stands).
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @return array{kind: string, detail: string}|null
     */
    private static function whereTie(array $t, int $j, string $table, string $alias): ?array
    {
        $depth = 0;
        for ($n = count($t); $j < $n; $j++) {
            $tok = $t[$j];
            if ($tok['k'] === 'punct' && $tok['v'] === '(') {
                $depth++;
            } elseif ($tok['k'] === 'punct' && $tok['v'] === ')') {
                if ($depth === 0) {
                    return null;
                }
                $depth--;
            } elseif ($depth === 0 && $tok['k'] === 'hole' && !self::namesAClause($tok['v'])) {
                return null;
            } elseif ($depth === 0 && $tok['k'] === 'word' && $tok['u'] === 'WHERE' && ($t[$j - 1]['v'] ?? '') !== '.') {
                $verdict = self::judge(self::condition($t, $j + 1), $table, $alias, false);

                return $verdict['kind'] === 'tied' ? ['kind' => 'tied', 'detail' => 'WHERE ' . $verdict['detail']] : null;
            } elseif ($depth === 0 && $tok['k'] === 'word' && in_array($tok['u'], ['GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION'], true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * A FROM or UPDATE list: a comma-joined account table, and a subquery's
     * first table.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @param array<int, int> $depths
     * @param array<string, true> $accountTables
     * @return list<array{kind: string, table: string, detail: string}>
     */
    private static function tableList(array $t, int $i, array $depths, array $accountTables): array
    {
        $out = [];
        $j = $i + 1;
        $first = true;
        while (true) {
            $ref = self::tableRef($t, $j, $accountTables);
            if ($ref === null) {
                return $out;
            }
            if ($ref['kind'] === 'table' && isset($accountTables[strtolower($ref['table'])])) {
                if (!$first) {
                    $out[] = ['kind' => 'comma join', 'table' => $ref['table'], 'detail' => self::excerpt($t, $i)];
                } elseif ($t[$i]['u'] === 'FROM' && $depths[$i] > 0) {
                    $out[] = self::subquery($t, $ref['next'], $ref['table'], $ref['alias']) + ['table' => $ref['table']];
                }
            } elseif ($ref['kind'] !== 'table' && $ref['kind'] !== 'derived' && !$first) {
                $out[] = ['kind' => $ref['kind'] . ' after a comma', 'table' => $ref['table'], 'detail' => self::excerpt($t, $i)];
            } elseif ($ref['kind'] !== 'table' && $ref['kind'] !== 'derived' && $t[$i]['u'] === 'FROM' && $depths[$i] > 0) {
                $out[] = ['kind' => 'subquery ' . $ref['kind'], 'table' => $ref['table'], 'detail' => self::excerpt($t, $i)];
            }
            $j = $ref['next'];
            if (($t[$j]['v'] ?? '') !== ',' || ($t[$j]['k'] ?? '') !== 'punct') {
                return $out;
            }
            $j++;
            $first = false;
        }
    }

    /**
     * A subquery's WHERE, from the token after its first table.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @return array{kind: string, detail: string}
     */
    private static function subquery(array $t, int $j, string $table, string $alias): array
    {
        // Skip its joins (each is judged on its own) to its WHERE, at its depth.
        $depth = 0;
        for ($n = count($t); $j < $n; $j++) {
            $tok = $t[$j];
            if ($tok['k'] === 'punct' && $tok['v'] === '(') {
                $depth++;
            } elseif ($tok['k'] === 'punct' && $tok['v'] === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && $tok['k'] === 'word' && $tok['u'] === 'WHERE') {
                return self::judge(self::condition($t, $j + 1), $table, $alias, true);
            } elseif ($depth === 0 && $tok['k'] === 'word' && in_array($tok['u'], ['GROUP', 'ORDER', 'HAVING', 'LIMIT', 'UNION'], true)) {
                break;
            } elseif ($depth === 0 && $tok['k'] === 'hole' && !self::namesAClause($tok['v'])) {
                return ['kind' => 'subquery with runtime text before its WHERE', 'detail' => $tok['v']];
            }
        }

        return ['kind' => 'subquery without a WHERE', 'detail' => $table];
    }

    /**
     * A table reference at $j: a name (with optional AS alias and index
     * hints), a derived table, or a hole.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @param array<string, true> $accountTables
     * @return array{kind: string, table: string, alias: string, next: int}|null
     */
    private static function tableRef(array $t, int $j, array $accountTables): ?array
    {
        $tok = $t[$j] ?? null;
        if ($tok === null) {
            return null;
        }
        if ($tok['k'] === 'punct' && $tok['v'] === '(') {
            $close = self::closeParen($t, $j);
            [$alias, $next] = self::alias($t, $close + 1);

            return ['kind' => 'derived', 'table' => '(…)', 'alias' => $alias, 'next' => $next];
        }
        if ($tok['k'] === 'hole') {
            return ['kind' => 'table built at runtime', 'table' => $tok['v'], 'alias' => '', 'next' => $j + 1];
        }
        if ($tok['k'] !== 'word' && $tok['k'] !== 'name') {
            return null;
        }
        if ($tok['k'] === 'word' && in_array($tok['u'], self::CLAUSE_WORDS, true)) {
            return null;
        }
        $table = $tok['v'];
        $j++;
        if (($t[$j]['v'] ?? '') === '.' && in_array($t[$j + 1]['k'] ?? '', ['word', 'name'], true)) {
            $table = $t[$j + 1]['v'];
            $j += 2;
        }
        if (($t[$j]['k'] ?? '') === 'hole' && empty($t[$j]['g']) && !isset($accountTables[strtolower($table)])) {
            // `202_tracking_{$n}`-style names: a name finished at runtime.
            // It can be an account-owned table only when one starts so.
            $prefix = strtolower($table);
            $could = array_filter(array_keys($accountTables), static fn (string $name): bool => str_starts_with($name, $prefix));
            if ($could === []) {
                [$alias, $next] = self::alias($t, $j + 1);

                return ['kind' => 'table', 'table' => $table . '…', 'alias' => $alias, 'next' => $next];
            }

            return ['kind' => 'table built at runtime', 'table' => $table . '…' . $t[$j]['v'], 'alias' => '', 'next' => $j + 1];
        }
        [$alias, $next] = self::alias($t, $j);
        if ($alias === '') {
            $alias = $table;
        }

        return ['kind' => 'table', 'table' => $table, 'alias' => $alias, 'next' => $next];
    }

    /**
     * @param list<array{k: string, v: string, u: string}> $t
     * @return array{0: string, 1: int} the alias ('' for none) and the index after it and its hints
     */
    private static function alias(array $t, int $j): array
    {
        $alias = '';
        if (($t[$j]['k'] ?? '') === 'word' && $t[$j]['u'] === 'AS') {
            $j++;
        }
        $tok = $t[$j] ?? null;
        if ($tok !== null && ($tok['k'] === 'name' || ($tok['k'] === 'word' && !in_array($tok['u'], self::CLAUSE_WORDS, true)))) {
            $alias = $tok['v'];
            $j++;
        } elseif ($tok !== null && $tok['k'] === 'hole') {
            $alias = "\x01" . $tok['v'];
            $j++;
        }
        // Index hints: USE|FORCE|IGNORE INDEX|KEY [FOR …] (list).
        while (($t[$j]['k'] ?? '') === 'word' && in_array($t[$j]['u'], ['USE', 'FORCE', 'IGNORE'], true)
            && in_array($t[$j + 1]['u'] ?? '', ['INDEX', 'KEY'], true)) {
            $j += 2;
            while (($t[$j]['k'] ?? '') === 'word' && in_array($t[$j]['u'], ['FOR', 'JOIN', 'ORDER', 'GROUP', 'BY'], true)) {
                $j++;
            }
            if (($t[$j]['v'] ?? '') === '(') {
                $j = self::closeParen($t, $j) + 1;
            }
        }

        return [$alias, $j];
    }

    /** @param list<array{k: string, v: string, u: string}> $t */
    private static function closeParen(array $t, int $open): int
    {
        $depth = 0;
        for ($j = $open, $n = count($t); $j < $n; $j++) {
            if ($t[$j]['k'] !== 'punct') {
                continue;
            }
            if ($t[$j]['v'] === '(') {
                $depth++;
            } elseif ($t[$j]['v'] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return count($t) - 1;
    }

    /**
     * The tokens of a condition from $j to the first clause word, `,` or
     * unmatched `)` at its own top level.
     *
     * $open is set when nothing ended it: it ran to the end of the text.
     *
     * @param list<array{k: string, v: string, u: string}> $t
     * @return list<array{k: string, v: string, u: string}>
     */
    private static function condition(array $t, int $j, bool &$open = false): array
    {
        $out = [];
        $depth = 0;
        $open = false;
        for ($n = count($t); true; $j++) {
            if ($j >= $n) {
                $open = $depth === 0;
                break;
            }
            $tok = $t[$j];
            if ($tok['k'] === 'punct' && $tok['v'] === '(') {
                $depth++;
            } elseif ($tok['k'] === 'punct' && $tok['v'] === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && (($tok['k'] === 'punct' && in_array($tok['v'], [',', ';'], true))
                || ($tok['k'] === 'word' && in_array($tok['u'], self::CONDITION_END_WORDS, true)
                    && ($t[$j - 1]['v'] ?? '') !== '.'
                    // LEFT( and RIGHT( are the string functions, not a join.
                    && !(in_array($tok['u'], ['LEFT', 'RIGHT'], true) && ($t[$j + 1]['v'] ?? '') === '(')))) {
                break;
            }
            $out[] = $tok;
        }

        return $out;
    }

    /**
     * Whether a condition ties $alias's user_id, as a top-level conjunct.
     *
     * @param list<array{k: string, v: string, u: string}> $c
     * @return array{kind: string, detail: string}
     */
    private static function judge(array $c, string $table, string $alias, bool $unqualifiedIsOwn): array
    {
        if (str_starts_with($alias, "\x01")) {
            return ['kind' => 'alias built at runtime', 'detail' => substr($alias, 1)];
        }
        // What follows a condition can be a hole that is the next clause:
        // `ON … $whereClause`, `ON … {$joins}`. Only a hole that names a
        // clause, after the last complete conjunct, is read that way.
        $trailing = [];
        while ($c !== [] && $c[count($c) - 1]['k'] === 'hole' && self::namesAClause($c[count($c) - 1]['v'])) {
            array_unshift($trailing, array_pop($c)['v']);
        }
        $c = self::unwrap($c);
        if ($c === []) {
            return $trailing === []
                ? ['kind' => 'empty condition', 'detail' => $table]
                : ['kind' => 'condition built at runtime', 'detail' => '{' . implode('} {', $trailing) . '}'];
        }
        foreach ($c as $k => $tok) {
            if ($tok['k'] === 'hole' && !self::isValueHole($c, $k)) {
                return ['kind' => 'condition built at runtime', 'detail' => self::render($c)];
            }
        }
        $conjuncts = [[]];
        $depth = 0;
        $between = false;
        foreach ($c as $tok) {
            if ($tok['k'] === 'punct' && $tok['v'] === '(') {
                $depth++;
            } elseif ($tok['k'] === 'punct' && $tok['v'] === ')') {
                $depth--;
            }
            if ($depth === 0 && (($tok['k'] === 'word' && in_array($tok['u'], ['OR', 'XOR'], true)) || ($tok['k'] === 'op' && $tok['v'] === '||'))) {
                return ['kind' => 'OR at the top of the condition', 'detail' => self::render($c)];
            }
            if ($depth === 0 && $tok['k'] === 'word' && $tok['u'] === 'BETWEEN') {
                $between = true;
            } elseif ($depth === 0 && (($tok['k'] === 'word' && $tok['u'] === 'AND') || ($tok['k'] === 'op' && $tok['v'] === '&&'))) {
                if ($between && $tok['k'] === 'word') {
                    $between = false; // BETWEEN x AND y: this AND is the range's
                } else {
                    $conjuncts[] = [];
                    continue;
                }
            }
            $conjuncts[count($conjuncts) - 1][] = $tok;
        }
        $tied = false;
        foreach ($conjuncts as $conjunct) {
            $tied = $tied || self::isTie(self::unwrap($conjunct), $alias, $unqualifiedIsOwn);
        }
        $detail = self::render($c) . ($trailing === [] ? '' : ' {' . implode('} {', $trailing) . '}');

        return $tied
            ? ['kind' => 'tied', 'detail' => $detail]
            : ['kind' => 'no user_id tie', 'detail' => $detail];
    }

    /**
     * A hole whose PHP source names a following clause: a plain variable or
     * a class constant whose name contains where, join, order, group,
     * limit or having (`$whereClause`, `{$joins}`, `$cfJoins`,
     * `self::CLICK_JOIN`). A constant holding SQL is scanned where it is
     * defined; a variable's SQL where it is built.
     */
    public static function namesAClause(string $source): bool
    {
        if (preg_match('/^\{?(?:\$\w+|(?:self|static)::[A-Z_]+)\}?$/', $source) !== 1) {
            return false;
        }

        return preg_match('/where|join|order|group|limit|having/i', $source) === 1;
    }

    /**
     * A hole the condition uses as a value: an operand of a comparison,
     * LIKE, BETWEEN … AND … or arithmetic, or an element of an IN (…) list.
     *
     * @param list<array{k: string, v: string, u: string}> $c
     */
    private static function isValueHole(array $c, int $k): bool
    {
        $before = $c[$k - 1] ?? null;
        $after = $c[$k + 1] ?? null;
        $operator = static fn (?array $tok): bool => $tok !== null && (($tok['k'] === 'op' && in_array($tok['v'], self::COMPARISON, true))
            || ($tok['k'] === 'punct' && in_array($tok['v'], ['+', '-', '*', '/'], true))
            || ($tok['k'] === 'word' && in_array($tok['u'], ['LIKE', 'BETWEEN', 'INTERVAL'], true)));
        if ($operator($before)) {
            return true;
        }
        $starts = $before === null || ($before['k'] === 'punct' && $before['v'] === '(')
            || ($before['k'] === 'word' && in_array($before['u'], ['AND', 'OR', 'NOT'], true));
        if ($starts && $operator($after) && !($after['k'] === 'word' && $after['u'] === 'INTERVAL')) {
            return true;
        }
        if ($before !== null && $before['k'] === 'word' && $before['u'] === 'AND') {
            // The upper bound of BETWEEN … AND ….
            for ($j = $k - 2; $j >= 0 && $j >= $k - 6; $j--) {
                if ($c[$j]['k'] === 'word' && $c[$j]['u'] === 'BETWEEN') {
                    return true;
                }
                if ($c[$j]['k'] === 'word' && in_array($c[$j]['u'], ['AND', 'OR'], true)) {
                    break;
                }
            }
        }
        // An element of IN (…): back over `value ,` pairs to `IN (`.
        $j = $k - 1;
        while ($j >= 1 && $c[$j]['k'] === 'punct' && $c[$j]['v'] === ',' && in_array($c[$j - 1]['k'], ['hole', 'str', 'word', 'param'], true)) {
            $j -= 2;
        }
        if ($j >= 1 && $c[$j]['k'] === 'punct' && $c[$j]['v'] === '(' && $c[$j - 1]['k'] === 'word' && $c[$j - 1]['u'] === 'IN'
            && $after !== null && $after['k'] === 'punct' && in_array($after['v'], [',', ')'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param list<array{k: string, v: string, u: string}> $c
     */
    private static function isTie(array $c, string $alias, bool $unqualifiedIsOwn): bool
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
        foreach ([[$left, $right], [$right, $left]] as [$own, $value]) {
            if (self::isOwnUserId($own, $alias, $unqualifiedIsOwn) && self::isUserValue($value, $alias)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{k: string, v: string, u: string}> $side */
    private static function isOwnUserId(array $side, string $alias, bool $unqualifiedIsOwn): bool
    {
        if (count($side) === 3 && $side[1]['v'] === '.' && in_array($side[0]['k'], ['word', 'name'], true)
            && in_array($side[2]['k'], ['word', 'name'], true)) {
            return strcasecmp($side[0]['v'], $alias) === 0 && strtolower($side[2]['v']) === 'user_id';
        }

        return $unqualifiedIsOwn && count($side) === 1 && in_array($side[0]['k'], ['word', 'name'], true)
            && strtolower($side[0]['v']) === 'user_id';
    }

    /** @param list<array{k: string, v: string, u: string}> $side */
    private static function isUserValue(array $side, string $alias): bool
    {
        if (count($side) === 3 && $side[1]['v'] === '.' && in_array($side[0]['k'], ['word', 'name'], true)
            && in_array($side[2]['k'], ['word', 'name'], true)) {
            return strcasecmp($side[0]['v'], $alias) !== 0 && strtolower($side[2]['v']) === 'user_id';
        }
        if (count($side) !== 1) {
            return false;
        }
        $tok = $side[0];
        if ($tok['k'] === 'param') {
            return true;
        }
        if (in_array($tok['k'], ['word', 'str'], true) && preg_match('/^[1-9][0-9]*$/', $tok['v']) === 1) {
            return true; // a fixed account: `2up.user_id = 1`, the install's own preferences
        }
        $source = $tok['k'] === 'hole' ? $tok['v'] : ($tok['k'] === 'str' ? $tok['u'] : '');

        return $source !== '' && self::namesAUserId($source);
    }

    /**
     * A hole's PHP source names a user id: it is a plain variable,
     * property, array element or constant (optionally cast to int or
     * string), and its last name is one of user_id, userId, user, uid, u or
     * user_own_id (the legacy pages' signed-in account) — `$mysql['user_id']`,
     * `{$this->userId}`, `(int) $userId`, `$plan['user']`, `$u`. A call, an
     * expression or any other name is not read as a user id.
     */
    public static function namesAUserId(string $source): bool
    {
        $source = trim($source, '{} ');
        $source = (string) preg_replace('/^\((?:int|string)\)\s*/i', '', $source);
        if (preg_match('/^\$[A-Za-z_]\w*(?:->\w+|\[\s*[\'"]?\w+[\'"]?\s*\]|::\$?\w+)*$/', $source) !== 1
            && preg_match('/^(?:self|static|[A-Z]\w*)::[A-Z_]+$/', $source) !== 1) {
            return false;
        }
        preg_match_all('/\w+/', $source, $m);
        $last = strtolower((string) end($m[0]));

        return in_array($last, ['user_id', 'userid', 'user', 'uid', 'u', 'user_own_id'], true);
    }

    /**
     * @param list<array{k: string, v: string, u: string}> $c
     * @return list<array{k: string, v: string, u: string}>
     */
    private static function unwrap(array $c): array
    {
        while (count($c) >= 2 && $c[0]['v'] === '(' && $c[0]['k'] === 'punct' && self::closeParen($c, 0) === count($c) - 1) {
            $c = array_slice($c, 1, -1);
        }

        return $c;
    }

    /** @param list<array{k: string, v: string, u: string}> $c */
    private static function render(array $c): string
    {
        $out = '';
        foreach ($c as $tok) {
            $out .= match (true) {
                $tok['k'] === 'hole' => '{' . $tok['v'] . '}',
                $tok['k'] === 'str' => "'" . $tok['v'] . "'",
                // A name in backticks as written, and a digit-led alias
                // (the legacy `2c`) quoted too: rendered SQL, unambiguous.
                $tok['k'] === 'name', $tok['k'] === 'word' && preg_match('/^[0-9]+[A-Za-z][A-Za-z0-9]*$/', $tok['v']) === 1 => '`' . $tok['v'] . '`',
                default => $tok['v'],
            } . ' ';
        }
        $out = str_replace([' . ', "\x01", "\x02"], ['.', '{', '}'], $out);

        return trim(mb_strimwidth($out, 0, 200, '…'));
    }

    /** @param list<array{k: string, v: string, u: string}> $t */
    private static function excerpt(array $t, int $i): string
    {
        return self::render(array_slice($t, max(0, $i - 2), 14));
    }
}
