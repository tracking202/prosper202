<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use PhpToken;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * What `_mysqli_query()` answers is tested for false before it is used.
 *
 * It answers false for a statement the server refused (logged by
 * p202LogFailedQuery()), and false read as a result is the silent case
 * CLAUDE.md #1 names: `->num_rows` on it is an \Error naming nothing about
 * the database, a `fetch_assoc()` loop is "no rows", and a write whose
 * answer is never read reports success over nothing. A call standing alone
 * is MysqliQueryResultIsNotDiscardedTest's; this reads every other call.
 *
 * A call is tested when, read from the tokens, the branch its failure
 * takes refuses:
 *
 *  - it is the whole condition of an `if`/`elseif`, and either the
 *    condition tests it for failure (`!_mysqli_query($sql)`,
 *    `_mysqli_query($sql) === false`) and its branch refuses, or tests it
 *    for success (`_mysqli_query($sql)`, `$r = _mysqli_query($sql)`) and an
 *    `else` refuses;
 *  - or it is assigned, `$r = _mysqli_query(...);` as a statement of its
 *    own, and the first appearance of `$r` after it, in the same function,
 *    is the whole condition of an `if`/`elseif` testing it for failure
 *    (`!$r`, `$r === false`, `false === $r`, `!$r instanceof mysqli_result`,
 *    `!($r instanceof mysqli_result)`), whose branch refuses.
 *
 * A branch refuses at its own top level: it throws, ends the request
 * (`die`, `exit`, a call declared `never`: `record_mysql_error()`,
 * `p202_account_redirect()`), returns the legacy API's database error, says
 * so to the person (`p202_account_flash('bad', ...)`), or files the page's
 * form error (`$error[...] = ...`). A bare `return`, `continue` or `break`
 * hands the failure on as an answer, so it is a fold; so is a condition with
 * no else, which goes on past the failure (`if (_mysqli_query($sql)) {
 * ... }` stopped a redirector's removal half way without a word), a call
 * tested together with another condition, and a `while` on one.
 *
 * Anything else that reads `$r` first is a fold: a test whose failure
 * answers something that reads like an answer (`$r ? $r->fetch_assoc() :
 * null`, `if ($r && ...)`, `$ok = $r !== false`, a failure branch that only
 * sets a default). Folding is a decision, so each is listed in FOLDED by
 * function, with how many and why the answer it gives is right; the list
 * only shrinks. And anything this scan cannot read as one of those is
 * reported, the loud direction: `$r` used before a test (`->`, `[`), handed
 * to a function, copied, returned, reassigned, captured by a closure, or
 * never read at all; the call chained, passed on or returned itself.
 *
 * Not read: a result reached through a variable that names no
 * `_mysqli_query()` (a call through `call_user_func()`, a wrapper that
 * returns its answer). The first appearance is found in source order, so a
 * test written in one branch of an `if` reads as the test for the other.
 */
final class MysqliQueryResultIsTestedTest extends TestCase
{
    /**
     * file::function => [how many results it folds, why the answer each
     * fold gives is the right one]. The count is held exactly.
     *
     * @var array<string, array{int, string}>
     */
    private const FOLDED = [
        '202-account/administration.php::' => [
            1,
            'The sign-in log: its panel says the log could not be read (`if (!$user_log_result)` in the markup),'
            . ' and the rest of the page is still drawn.',
        ],
        '202-account/user-management.php::' => [
            1,
            'The list of users: the page says it could not be read ($usersReadable) and lists none, rather than'
            . ' an empty list.',
        ],
        '202-config/Database/SchemaInstaller.php::executeStatement' => [
            1,
            'Records the failure in $this->errors, which install() reports, and answers false.',
        ],
        '202-config/Database/SchemaInstaller.php::disableStrictMode' => [
            1,
            'Clearing the session\'s sql_mode for the install: a failure is logged and the install goes on; a'
            . ' table that then fails to create is reported by executeStatement().',
        ],
        '202-config/Database/SchemaInstaller.php::setCollations' => [
            1,
            'The 202_ips_v6 collation for the install: a failure is logged and the install goes on.',
        ],
        '202-config/class-dataengine.php::doSummary' => [
            2,
            'Not reached: doQuery()\'s one caller hands it an INSERT … SELECT (ClickRollupSql), which answers true'
            . ' and returns before doSummary(). Its insert and its window mark log a failure.',
        ],
        '202-config/functions-auth.php::remember_me_on_auth' => [
            1,
            'A remember-me key that cannot be stored sets no cookie: the person stays signed in for the session,'
            . ' and a cookie whose key is not stored would be refused on every later visit.',
        ],
        '202-config/functions-auth.php::remember_me_on_logged_out' => [
            1,
            'A remember-me key that cannot be read signs nobody in, as a key that cannot be verified does: the'
            . ' visitor is asked to sign in, and the cookie is kept for a request that can read it.',
        ],
        '202-config/functions-tracking202.php::createPublisherIds' => [
            1,
            'Run by the installer and by the 1.9.47 upgrade rung, which is frozen (CLAUDE.md #33): users left'
            . ' without a publisher id are filled by the next run.',
        ],
        '202-config/functions-tracking202.php::getDashEmail' => [
            1,
            'Every signed-in page\'s footer reads it: an address that cannot be read is unknown (false), and the'
            . ' page is still drawn.',
        ],
        '202-lost-pass.php::' => [
            2,
            'The reset key\'s write is reached only for a matching account, so its failure answers as every request'
            . ' does, or the page would tell a stranger the account exists. Without the install\'s address the'
            . ' email carries the key and the page, with no link (PasswordResetLink).',
        ],
    ];

    /** Statements a failure branch may refuse with, at its own top level. */
    private const REFUSALS = [T_THROW, T_EXIT];

    /**
     * Calls that refuse as a statement of their own: each is declared
     * `never` (testTheRefusingCallsNeverReturn()), so nothing after one runs.
     */
    private const REFUSING_CALLS = ['record_mysql_error', 'p202_account_redirect'];

    /**
     * Calls whose answer, returned, is an error the caller sends as one:
     * `return legacy_api_database_error($db);`.
     */
    private const ERROR_ANSWERS = ['legacy_api_database_error'];

    /** @return array<string, list<string>> file => "line (function): why" of each site not tested */
    private static function untested(): array
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (stripos($source, '_mysqli_query') === false) {
                continue;
            }
            $folded = [];
            foreach (self::sitesIn($source) as [$line, $function, $kind, $why]) {
                if ($kind === 'tested' || $kind === 'discarded') {
                    continue;
                }
                if ($kind === 'folded' && isset(self::FOLDED[$path . '::' . $function])) {
                    $folded[$function][] = $line;
                    continue;
                }
                $found[$path][] = "$line ($function): $why";
            }
            foreach ($folded as $function => $lines) {
                $listed = self::FOLDED[$path . '::' . $function][0];
                if (count($lines) !== $listed) {
                    $found[$path][] = implode(', ', $lines) . " ($function): " . count($lines)
                        . " folded, FOLDED weighs $listed";
                }
            }
        }

        return $found;
    }

    public function testEveryMysqliQueryResultIsTestedForFalse(): void
    {
        $found = self::untested();

        $this->assertSame([], $found, sprintf(
            "These _mysqli_query() results are not tested for false before they are used:\n%s\n"
            . 'It answers false for a statement the server refused. Assign it and test it in the next'
            . ' condition, refusing there (throw, record_mysql_error(), the page\'s $error[...]);'
            . ' list a fold in FOLDED only when the answer it gives is right.',
            implode("\n", array_map(
                static fn (string $f, array $sites): string => "  $f:\n    " . implode("\n    ", $sites),
                array_keys($found),
                $found
            ))
        ));
    }

    public function testEveryFoldedEntryIsStillOne(): void
    {
        $files = SourceScan::phpFiles();
        $stale = [];
        foreach (self::FOLDED as $id => [$count, $why]) {
            [$file, $function] = explode('::', $id);
            $folds = isset($files[$file]) ? array_filter(
                self::sitesIn($files[$file]),
                static fn (array $site): bool => $site[1] === $function && $site[2] === 'folded'
            ) : [];
            if (count($folds) !== $count) {
                $stale[] = "$id weighs $count and folds " . count($folds) . " ($why): remove it, or set the count";
            }
        }
        $this->assertSame([], $stale);
    }

    public function testTheRefusingCallsNeverReturn(): void
    {
        $declared = [];
        foreach (SourceScan::phpFiles() as $source) {
            foreach (self::REFUSING_CALLS as $name) {
                if (preg_match('/\bfunction\s+' . $name . '\s*\([^)]*\)\s*:\s*never\b/i', $source) === 1) {
                    $declared[] = $name;
                }
            }
        }
        sort($declared);
        $expected = self::REFUSING_CALLS;
        sort($expected);

        $this->assertSame($expected, $declared, 'a call read as a refusal must be declared once, returning never');
    }

    public function testTheScanReadsTheTree(): void
    {
        $calls = 0;
        foreach (SourceScan::phpFiles() as $source) {
            if (stripos($source, '_mysqli_query') !== false) {
                $calls += count(self::sitesIn($source));
            }
        }
        // 81 when this was written; a scan that stopped finding calls passes everything.
        $this->assertGreaterThan(60, $calls, 'the scan found too few _mysqli_query() calls to be reading the tree');
    }

    /** @return iterable<string, array{string, string}> code inside a function => kind */
    public static function shapes(): iterable
    {
        $q = '_mysqli_query($sql)';
        yield 'alone' => ["$q;", 'discarded'];
        yield 'the condition, an else refuses' => ["if ($q) { ok(); } else { throw new E(); }", 'tested'];
        yield 'the condition, braceless else' => ["if ($q) ok(); else record_mysql_error(\$sql);", 'tested'];
        yield 'the condition, a bad flash' => ["if ($q) { ok(); } else { p202_account_flash('bad', 'x'); }", 'tested'];
        yield 'negated condition' => ["if (!$q) { throw new E(); }", 'tested'];
        yield 'compared in the condition' => ["if ($q === false) { die(); }", 'tested'];
        yield 'assigned in the condition, an else refuses' => [
            "if (\$r = $q) { ok(); } else { record_mysql_error(\$sql); }",
            'tested',
        ];
        yield 'the condition, no else' => ["if ($q) { ok(); }", 'folded'];
        yield 'nested, no else' => ["if ($q) { if ($q) { ok(); } }", 'folded'];
        yield 'the condition, an else that says it went well' => [
            "if ($q) { ok(); } else { p202_account_flash('ok', 'x'); }",
            'folded',
        ];
        yield 'the condition, an elseif' => ["if ($q) { ok(); } elseif (\$b) { throw new E(); }", 'folded'];
        yield 'assigned in the condition, no else' => ["if (\$r = $q) { ok(); }", 'folded'];
        yield 'negated, a bare return' => ["if (!$q) { return false; }", 'folded'];
        yield 'one operand of two' => ["if ($q && $q) { ok(); }", 'folded'];
        yield 'in a while' => ["while ($q) { ok(); }", 'folded'];
        yield 'with the connection' => [
            "\$r = _mysqli_query(\$db, \$sql); if (\$r === false) { throw new E(); }",
            'tested',
        ];
        yield 'fully qualified, other case' => ["\$r = \\_MySQLi_Query(\$sql); if (!\$r) { throw new E(); }", 'tested'];
        yield '=== false, throws' => ["\$r = $q; if (\$r === false) { throw new E(); }", 'tested'];
        yield 'false ===, the legacy error' => [
            "\$r = $q; if (false === \$r) { return legacy_api_database_error(\$db); }",
            'tested',
        ];
        yield 'a flash and a redirect' => [
            "\$r = $q; if (!\$r) { p202_account_flash('bad', 'x'); p202_account_redirect('y'); }",
            'tested',
        ];
        yield '!, record_mysql_error' => ["\$r = $q; if (!\$r) { record_mysql_error(\$sql); }", 'tested'];
        yield 'instanceof, exits' => ["\$r = $q; if (!\$r instanceof mysqli_result) { die(); }", 'tested'];
        yield 'instanceof, qualified, parenthesized' => [
            "\$r = $q; if (!(\$r instanceof \\mysqli_result)) { exit; }",
            'tested',
        ];
        yield 'braceless refusal' => ["\$r = $q; if (\$r === false) throw new E();", 'tested'];
        yield 'form error' => [
            "\$r = $q; if (\$r === false) { \$error['x'] = 'y'; } elseif (\$r->num_rows) { a(); }",
            'tested',
        ];
        yield 'elseif' => ["\$r = $q; if (\$a) { b(); } elseif (\$r === false) { throw new E(); }", 'tested'];
        yield 'in a try, tested after it' => [
            "try { \$r = $q; } catch (E \$e) { throw \$e; } if (\$r === false) { throw new E(); }",
            'tested',
        ];
        yield 'a bare return' => ["\$r = $q; if (false === \$r) { return []; }", 'folded'];
        yield 'another call returned' => ["\$r = $q; if (!\$r) { return other(\$db); }", 'folded'];
        yield 'a loop item skipped' => ["foreach (\$a as \$s) { \$r = $q; if (!\$r) { continue; } }", 'folded'];
        yield 'used first' => ["\$r = $q; \$row = \$r->fetch_assoc();", 'unread'];
        yield 'counted first' => ["\$r = $q; if (\$r->num_rows == 0) { a(); }", 'unread'];
        yield 'nullsafe first' => ["\$r = $q; \$row = \$r?->fetch_assoc();", 'unread'];
        yield 'never read' => ["\$r = $q; ok();", 'unread'];
        yield 'reassigned unread' => ["\$r = $q; \$r = other(); if (!\$r) { return; }", 'unread'];
        yield 'handed on' => ["\$r = $q; rows(\$r);", 'unread'];
        yield 'copied' => ["\$r = $q; \$s = \$r; if (!\$s) { return; }", 'unread'];
        yield 'returned' => ["\$r = $q; return \$r;", 'unread'];
        yield 'captured' => ["\$r = $q; \$f = function () use (\$r) { return \$r; };", 'unread'];
        yield 'tested later' => ["\$r = $q; \$n = \$r->num_rows; if (!\$r) { return; }", 'unread'];
        yield 'chained' => ['$row = ' . $q . '->fetch_assoc();', 'unread'];
        yield 'passed on' => ["rows($q);", 'unread'];
        yield 'returned itself' => ["return $q;", 'unread'];
        yield 'in a call in the condition' => ["if (rows($q)) { ok(); }", 'unread'];
        yield 'assigned inside an expression' => ["\$x = 1 + (\$r = $q); if (!\$r) { return; }", 'unread'];
        yield 'truthy branch' => ["\$r = $q; if (\$r) { \$row = \$r->fetch_assoc(); }", 'folded'];
        yield 'ternary' => ["\$r = $q; \$row = \$r ? \$r->fetch_assoc() : null;", 'folded'];
        yield 'and' => ["\$r = $q; if (\$r && \$r->num_rows > 0) { a(); }", 'folded'];
        yield 'a flag' => ["\$r = $q; \$ok = \$r !== false; if (\$ok) { a(); }", 'folded'];
        yield 'instanceof ternary' => [
            "\$r = $q; \$row = \$r instanceof mysqli_result ? \$r->fetch_assoc() : null;",
            'folded',
        ];
        yield 'failure branch sets a default' => [
            "\$r = $q; if (\$r === false) { \$rows = []; } else { \$rows = all(\$r); }",
            'folded',
        ];
        yield 'refusal only nested' => ["\$r = $q; if (!\$r) { if (\$strict) { throw new E(); } }", 'folded'];
        yield 'joined to another condition' => ["\$r = $q; if (\$r === false || \$skip) { throw new E(); }", 'folded'];
        yield 'loose' => ["\$r = $q; if (\$r == false) { throw new E(); }", 'folded'];
    }

    /** @dataProvider shapes */
    public function testTheScanReadsEachShape(string $code, string $kind): void
    {
        $sites = self::sitesIn("<?php\nfunction f(\$sql, \$db, \$a) {\n" . $code . "\n}\n");

        $this->assertNotSame([], $sites, 'no call found in: ' . $code);
        $this->assertSame([$kind], array_values(array_unique(array_column($sites, 2))), $code . ' => '
            . var_export($sites, true));
    }

    public function testASiteIsAttributedToTheFunctionItIsIn(): void
    {
        $source = "<?php\n\$top = _mysqli_query(\$a); if (!\$top) { die(); }\n"
            . "class C { function m() { \$r = _mysqli_query(\$b); \$r->num_rows; } }\n"
            . "function g() { \$f = function () { \$r = _mysqli_query(\$c); if (\$r) { a(); } }; }\n";

        $this->assertSame(
            [[2, '', 'tested'], [3, 'm', 'unread'], [4, 'g', 'folded']],
            array_map(static fn (array $s): array => array_slice($s, 0, 3), self::sitesIn($source))
        );
    }

    /**
     * Each _mysqli_query() call in a source: [line, the innermost named
     * function around it ('' at file scope; a closure reads as the function
     * it is in), kind, why], where kind is discarded, tested, folded or
     * unread.
     *
     * @return list<array{int, string, string, string}>
     */
    public static function sitesIn(string $source): array
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn (PhpToken $t): bool => !$t->isIgnorable()
        ));
        $bodies = self::functionBodies($tokens);
        $sites = [];
        foreach ($tokens as $i => $token) {
            if (
                SourceScan::callableName($token->id, $token->text) !== '_mysqli_query'
                || ($tokens[$i + 1]->text ?? '') !== '('
            ) {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            $notACall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];
            if ($prev !== null && $prev->is($notACall)) {
                continue;
            }
            $close = self::closing($tokens, $i + 1);
            [$kind, $why] = self::classify($tokens, $bodies, $i, $close);
            $sites[] = [$token->line, self::functionAt($tokens, $bodies, $i), $kind, $why];
        }

        return $sites;
    }

    /**
     * @param list<PhpToken> $tokens
     * @param list<array{int, int, string}> $bodies
     * @return array{string, string}
     */
    private static function classify(array $tokens, array $bodies, int $call, int $close): array
    {
        $after = $tokens[$close + 1] ?? null;
        if (
            $after !== null && $after->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
            || ($after?->text === '[')
        ) {
            return ['unread', 'its answer is used before it is tested'];
        }
        if (self::startsStatement($tokens, $call) && $after?->text === ';') {
            return ['discarded', 'its answer is thrown away'];
        }

        $assigned = ($tokens[$call - 1]->text ?? '') === '=' && ($tokens[$call - 2] ?? null)?->is(T_VARIABLE);
        $start = $assigned ? $call - 2 : $call;

        // In an if/elseif/while condition: the branch its failure takes has
        // to refuse, as an assigned result's does.
        $opener = self::conditionParen($tokens, $start);
        if ($opener !== null && ($tokens[$opener - 1] ?? null)?->is([T_IF, T_ELSEIF, T_WHILE])) {
            $closer = self::closing($tokens, $opener);
            $condition = self::conditionWith($tokens, $opener, $closer, $start, $close);
            if ($tokens[$opener - 1]->is(T_WHILE)) {
                return ['folded', 'a loop on it ends on its failure as on its last row'];
            }
            if (in_array($condition, ['! Q', '! ( Q )', 'Q === false', 'false === Q'], true)) {
                return self::branchRefuses($tokens, $closer + 1)
                    ? ['tested', '']
                    : ['folded', 'the branch that tests it for failure refuses nothing at its own level'];
            }
            if (in_array($condition, ['Q', '( Q )', 'Q !== false', 'false !== Q'], true)) {
                $else = self::elseBranch($tokens, $closer + 1);

                return $else !== null && self::branchRefuses($tokens, $else)
                    ? ['tested', '']
                    : ['folded', 'its failure falls through: no else refuses when it answers false'];
            }
            $before = $tokens[$start - 1];
            $next = $after?->text;
            if (
                (in_array($before->text, ['(', '!', '&&', '||'], true) || $before->is([T_LOGICAL_AND, T_LOGICAL_OR]))
                && (in_array($next, [')', '&&', '||', '===', '!==', '==', '!='], true)
                    || ($after?->is([T_LOGICAL_AND, T_LOGICAL_OR]) ?? false))
            ) {
                return ['folded', 'it is tested together with another condition'];
            }

            return ['unread', 'it is read inside the condition, not tested by it'];
        }

        if (!$assigned || !self::startsStatement($tokens, $start) || $after?->text !== ';') {
            return ['unread', 'its answer is handed on, not assigned and tested'];
        }

        return self::firstRead($tokens, $bodies, $tokens[$call - 2]->text, $close + 1);
    }

    /**
     * How the first appearance of $variable after $from, in the same
     * function, reads it.
     *
     * @param list<PhpToken> $tokens
     * @param list<array{int, int, string}> $bodies
     * @return array{string, string}
     */
    private static function firstRead(array $tokens, array $bodies, string $variable, int $from): array
    {
        [$scopeStart, $scopeEnd] = self::scopeOf($bodies, $from, count($tokens));
        for ($k = $from + 1; $k < $scopeEnd; $k++) {
            foreach ($bodies as [$start, $end]) {
                if ($start === $k && $start > $scopeStart) {
                    // Another function's body, declared here: its variables are its own.
                    $k = $end;
                    continue 2;
                }
            }
            $token = $tokens[$k];
            if (!$token->is(T_VARIABLE) || $token->text !== $variable) {
                continue;
            }

            $next = $tokens[$k + 1] ?? null;
            $prev = $tokens[$k - 1];
            $member = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];
            if ($next !== null && ($next->is($member) || $next->text === '[')) {
                return ['unread', "$variable is used before it is tested"];
            }
            if ($next?->text === '=' && self::startsStatement($tokens, $k)) {
                return ['unread', "$variable is assigned again before it is read"];
            }

            $opener = self::conditionParen($tokens, $k);
            $isCondition = $opener !== null && ($tokens[$opener - 1] ?? null)?->is([T_IF, T_ELSEIF]);
            if ($isCondition) {
                $closer = self::closing($tokens, $opener);
                if (self::isFailureTest(array_slice($tokens, $opener + 1, $closer - $opener - 1), $variable)) {
                    return self::branchRefuses($tokens, $closer + 1)
                        ? ['tested', '']
                        : ['folded', "the branch that tests $variable for failure refuses nothing at its own level"];
                }
            }

            $nextText = $next?->text;
            $tests = in_array($nextText, ['?', '&&', '||', '!==', '===', '!=', '=='], true)
                || ($next?->is([T_INSTANCEOF, T_LOGICAL_AND, T_LOGICAL_OR]) ?? false)
                || ($isCondition && $nextText === ')' && in_array($prev->text, ['(', '!'], true));
            if ($tests) {
                return ['folded', "$variable's failure is folded into an answer"];
            }

            return ['unread', "$variable is read but not tested"];
        }

        return ['unread', "$variable is never read"];
    }

    /**
     * Whether a condition's tokens are exactly one failure test of $variable.
     *
     * @param list<PhpToken> $condition
     */
    private static function isFailureTest(array $condition, string $variable): bool
    {
        $text = implode(' ', array_map(
            static fn (PhpToken $t): string => $t->is(T_NAME_FULLY_QUALIFIED) ? ltrim(strtolower($t->text), '\\')
                : strtolower($t->text),
            $condition
        ));
        $v = strtolower($variable);

        return in_array($text, [
            "! $v",
            "$v === false",
            "false === $v",
            "! $v instanceof mysqli_result",
            "! ( $v instanceof mysqli_result )",
        ], true);
    }

    /**
     * A condition's tokens as lower-case text, the call (with an assignment
     * of it) standing as `Q`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function conditionWith(array $tokens, int $opener, int $closer, int $start, int $close): string
    {
        $words = [];
        for ($k = $opener + 1; $k < $closer; $k++) {
            if ($k === $start) {
                $words[] = 'Q';
                $k = $close;
                continue;
            }
            $words[] = $tokens[$k]->is(T_NAME_FULLY_QUALIFIED)
                ? ltrim(strtolower($tokens[$k]->text), '\\')
                : strtolower($tokens[$k]->text);
        }

        return implode(' ', $words);
    }

    /**
     * Where the `else` branch of the `if` whose body starts at $at begins,
     * or null when it has none (an `elseif` is another condition, not a
     * branch that refuses).
     *
     * @param list<PhpToken> $tokens
     */
    private static function elseBranch(array $tokens, int $at): ?int
    {
        if (!isset($tokens[$at])) {
            return null;
        }
        $end = $tokens[$at]->text === '{' ? self::closing($tokens, $at) : self::statementEnd($tokens, $at);
        $next = $tokens[$end + 1] ?? null;

        return $next !== null && $next->is(T_ELSE) ? $end + 2 : null;
    }

    /**
     * Whether the branch starting at $at (a block, or one statement) holds,
     * at its own top level, a statement that refuses.
     *
     * @param list<PhpToken> $tokens
     */
    private static function branchRefuses(array $tokens, int $at): bool
    {
        $braced = ($tokens[$at]->text ?? '') === '{';
        $end = $braced ? self::closing($tokens, $at) : self::statementEnd($tokens, $at);
        $statementStart = true;
        $depth = 0;
        for ($k = $braced ? $at + 1 : $at; $k < $end; $k++) {
            $token = $tokens[$k];
            if ($depth === 0 && $statementStart) {
                $returned = $token->is(T_RETURN) ? ($tokens[$k + 1] ?? null) : null;
                $returnsAnError = $returned !== null
                    && in_array(SourceScan::callableName($returned->id, $returned->text), self::ERROR_ANSWERS, true)
                    && ($tokens[$k + 2]->text ?? '') === '(';
                if (
                    $token->is(self::REFUSALS)
                    || in_array(SourceScan::callableName($token->id, $token->text), self::REFUSING_CALLS, true)
                    || $returnsAnError
                    || ($token->is(T_VARIABLE) && $token->text === '$error' && ($tokens[$k + 1]->text ?? '') === '[')
                    || self::flashesBad($tokens, $k)
                ) {
                    return true;
                }
            }
            if (self::opens($token)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            }
            $statementStart = $depth === 0 && in_array($token->text, [';', '}'], true);
        }

        return false;
    }

    /**
     * Whether the statement at $k says the failure to the person:
     * `p202_account_flash('bad', ...)`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function flashesBad(array $tokens, int $k): bool
    {
        $kind = $tokens[$k + 2] ?? null;

        return SourceScan::callableName($tokens[$k]->id, $tokens[$k]->text) === 'p202_account_flash'
            && ($tokens[$k + 1]->text ?? '') === '('
            && $kind !== null && $kind->is(T_CONSTANT_ENCAPSED_STRING)
            && in_array($kind->text, ["'bad'", '"bad"'], true);
    }

    private static function opens(PhpToken $token): bool
    {
        return in_array($token->text, ['(', '[', '{'], true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]);
    }

    /** @param list<PhpToken> $tokens */
    private static function startsStatement(array $tokens, int $at): bool
    {
        $prev = $tokens[$at - 1] ?? null;

        return $prev === null
            || $prev->is([T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO])
            || in_array($prev->text, [';', '{', '}'], true);
    }

    /**
     * The index of the unmatched `(` around $at in its statement, or null.
     *
     * @param list<PhpToken> $tokens
     */
    private static function enclosingParen(array $tokens, int $at): ?int
    {
        $depth = 0;
        for ($k = $at - 1; $k >= 0; $k--) {
            $text = $tokens[$k]->text;
            if ($text === ')' || $text === ']') {
                $depth++;
            } elseif ($text === '(' || $text === '[') {
                if ($depth === 0) {
                    return $text === '(' ? $k : null;
                }
                $depth--;
            } elseif ($depth === 0 && in_array($text, [';', '{', '}'], true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * The `(` of the if/elseif/while condition $at is in, through the
     * grouping parentheses and `!` around it, or the innermost `(` when it
     * is in none.
     *
     * @param list<PhpToken> $tokens
     */
    private static function conditionParen(array $tokens, int $at): ?int
    {
        $opener = self::enclosingParen($tokens, $at);
        while (
            $opener !== null
            && !($tokens[$opener - 1] ?? null)?->is([T_IF, T_ELSEIF, T_WHILE])
            && in_array($tokens[$opener - 1]->text ?? '', ['(', '!'], true)
        ) {
            $outer = self::enclosingParen($tokens, $opener);
            if ($outer === null) {
                break;
            }
            $opener = $outer;
        }

        return $opener;
    }

    /** @param list<PhpToken> $tokens */
    private static function closing(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);
        for ($k = $open; $k < $count; $k++) {
            $text = $tokens[$k]->text;
            if (self::opens($tokens[$k])) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return $count;
    }

    /** @param list<PhpToken> $tokens */
    private static function statementEnd(array $tokens, int $at): int
    {
        $depth = 0;
        $count = count($tokens);
        for ($k = $at; $k < $count; $k++) {
            $text = $tokens[$k]->text;
            if (self::opens($tokens[$k])) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($text === ';' && $depth === 0) {
                return $k;
            }
        }

        return $count;
    }

    /**
     * Each function body: [index of `{`, index of `}`, name], the name
     * '{closure}' for an anonymous one. Arrow functions are not bodies:
     * they read the variables around them.
     *
     * @param list<PhpToken> $tokens
     * @return list<array{int, int, string}>
     */
    private static function functionBodies(array $tokens): array
    {
        $bodies = [];
        $count = count($tokens);
        foreach ($tokens as $i => $token) {
            if (!$token->is(T_FUNCTION)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if ($next?->text === '&') {
                $next = $tokens[$i + 2] ?? null;
            }
            $name = $next !== null && $next->is(T_STRING) ? $next->text : '{closure}';
            for ($k = $i + 1; $k < $count; $k++) {
                if ($tokens[$k]->text === ';') {
                    break;
                }
                if ($tokens[$k]->text === '{') {
                    $bodies[] = [$k, self::closing($tokens, $k), $name];
                    break;
                }
            }
        }

        return $bodies;
    }

    /**
     * [start, end) of the innermost function body around $at, or the file.
     *
     * @param list<array{int, int, string}> $bodies
     * @return array{int, int}
     */
    private static function scopeOf(array $bodies, int $at, int $count): array
    {
        $scope = [-1, $count];
        foreach ($bodies as [$start, $end]) {
            if ($start < $at && $at < $end && $start > $scope[0]) {
                $scope = [$start, $end];
            }
        }

        return $scope;
    }

    /**
     * @param list<PhpToken> $tokens
     * @param list<array{int, int, string}> $bodies
     */
    private static function functionAt(array $tokens, array $bodies, int $at): string
    {
        $named = '';
        $innermost = -1;
        foreach ($bodies as [$start, $end, $name]) {
            if ($start < $at && $at < $end && $name !== '{closure}' && $start > $innermost) {
                $innermost = $start;
                $named = $name;
            }
        }

        return $named;
    }
}
