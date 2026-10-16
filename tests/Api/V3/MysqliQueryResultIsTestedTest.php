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
 *    is the whole condition of an `if` testing it for failure (`!$r`,
 *    `$r === false`, `false === $r`, `!$r instanceof mysqli_result`,
 *    `!($r instanceof mysqli_result)`), whose branch refuses. That `if` is
 *    a statement of its own that runs whenever the assignment did: in the
 *    assignment's block, or one around it reached without leaving a loop
 *    (after a `try`, say). One inside a branch, in an `elseif`, after an
 *    `else` or a `case` label, or after the loop that assigned it tests one
 *    path, and is reported.
 *
 * A branch refuses at its own top level: it throws, ends the request
 * (`die`, `exit`, a call declared `never`: `record_mysql_error()`,
 * `p202_account_redirect()`), returns the legacy API's database error, says
 * so to the person (`p202_account_flash('bad', ...)`), or files the page's
 * form error (`$error[...] = ...`). The last two carry on past the if, so a
 * result with a name is tested by them only when nothing reads it on that
 * path: after the if chain to the end of its scope (unless the whole
 * variable is assigned again directly in a block the path is in), or from
 * the top of a loop around it. A bare `return`, `continue` or `break`
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
 * returns its answer).
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
        '202-account/administration.php::database_size' => [
            1,
            'The size panel says unknown: it is a figure the page shows, and unknown is what the failure tells.',
        ],
        '202-account/administration.php::p202_admin_scheduled_deletion' => [
            2,
            'The scheduled deletion: a failed read of the setting says it could not be read (and is logged);'
            . ' a failed read of the marker click names the click id only, which is what the setting holds.',
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

    /**
     * Calls that refuse as a statement of their own: each is declared
     * `never` (testTheRefusingCallsNeverReturn()), so nothing after one runs.
     * Called, that is: `record_mysql_error(...)` makes a closure and goes on.
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

    /**
     * The scan reads every call the text holds: a call it does not see is
     * reported nowhere, so each file's count of sites is held to a count
     * taken from the text by another means.
     */
    public function testTheScanReadsEveryCallInTheTree(): void
    {
        $calls = 0;
        $unread = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if (stripos($source, '_mysqli_query') === false) {
                continue;
            }
            $read = count(self::sitesIn($source));
            $written = self::callsWritten($source);
            if ($read !== $written) {
                $unread[] = "$file: the scan read $read calls, the text holds $written";
            }
            $calls += $read;
        }
        $this->assertSame([], $unread, 'the scan did not read every _mysqli_query() call');
        // 81 when this was written; a walk that found no files passes the rest.
        $this->assertGreaterThan(60, $calls, 'the scan found too few _mysqli_query() calls to be reading the tree');
    }

    public function testTheTextCountIsTheCallsWritten(): void
    {
        $this->assertSame(4, self::callsWritten("<?php\n_mysqli_query(\$a); \\_MYSQLI_QUERY (\$b);\n"
            . "if (\$r = _mysqli_query(\$c)) {}\n?><p>_mysqli_query(x)</p><?php\n"
            . "// _mysqli_query(\$d)\n\$s = '_mysqli_query(';\n\$o->_mysqli_query(\$e); X::_mysqli_query(\$f);\n"
            . "function _mysqli_query(\$g) {}\nreturn rows(_mysqli_query(\$h));\n"));
    }

    /**
     * The `_mysqli_query(` calls in a source, from its text: outside
     * comments, strings and markup, less a method of that name and the
     * function's own definition.
     */
    private static function callsWritten(string $source): int
    {
        $code = '';
        foreach (PhpToken::tokenize($source) as $token) {
            $notCode = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML];
            $code .= $token->is($notCode) ? ' ' : $token->text;
        }

        return (int) preg_match_all('/(?<![\w$>:\\\\])\\\\?_mysqli_query\s*\(/i', $code)
            - (int) preg_match_all('/\bfunction\s+&?\s*_mysqli_query\s*\(/i', $code);
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
        yield 'elseif' => ["\$r = $q; if (\$a) { b(); } elseif (\$r === false) { throw new E(); }", 'unread'];
        yield 'tested in one branch, used in the other' => [
            "\$r = $q; if (\$a) { if (!\$r) { throw new E(); } } else { \$r->c(); }",
            'unread',
        ];
        yield 'tested in a braceless branch' => [
            "\$r = $q; if (\$a) if (!\$r) { throw new E(); } \$r->c();",
            'unread',
        ];
        yield 'tested after a case label' => [
            "switch (\$a) { case 1: b(); \$r = $q; case 2: if (!\$r) { throw new E(); } }",
            'unread',
        ];
        yield 'tested after the loop that assigned it' => [
            "foreach (\$a as \$x) { \$r = $q; } if (!\$r) { throw new E(); }",
            'unread',
        ];
        yield 'tested after an alternative-syntax loop' => [
            "foreach (\$a as \$x): b(); \$r = $q; endforeach; if (!\$r) { throw new E(); }",
            'unread',
        ];
        yield 'tested after a do loop' => ["do { \$r = $q; } while (\$a); if (!\$r) { throw new E(); }", 'unread'];
        yield 'tested after the if that assigned it' => [
            "if (\$a) { \$r = $q; } if (!\$r) { throw new E(); }",
            'tested',
        ];
        yield 'tested after a block of markup' => [
            "\$r = $q; ?>\n<p>x</p>\n<?php if (!\$r) { throw new E(); }",
            'tested',
        ];
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
        yield 'a refusing call made a closure' => [
            "\$r = $q; if (\$r === false) { record_mysql_error(...); } \$r->c();",
            'folded',
        ];
        yield 'a refusing call, called' => [
            "\$r = $q; if (\$r === false) { record_mysql_error(\$sql); } \$r->c();",
            'tested',
        ];
        yield 'an error answer made a closure' => [
            "\$r = $q; if (!\$r) { return legacy_api_database_error(...); }",
            'folded',
        ];
        yield 'exit made a closure' => ["\$r = $q; if (!\$r) { exit(...); }", 'folded'];
        yield 'a bare exit' => ["\$r = $q; if (!\$r) { exit; }", 'tested'];
        yield 'loose' => ["\$r = $q; if (\$r == false) { throw new E(); }", 'folded'];
        yield 'a form error, read after the if' => [
            "\$r = $q; if (\$r === false) { \$error['db'] = 'x'; } \$r->fetch_assoc();",
            'unread',
        ];
        yield 'a form error, read in the else' => [
            "\$r = $q; if (\$r === false) { \$error['db'] = 'x'; } else { \$r->fetch_assoc(); } done();",
            'tested',
        ];
        yield 'a form error, read in an elseif' => [
            "\$r = $q; if (!\$r) { \$error['x'] = 1; } elseif (\$r->num_rows) { a(); } else { b(\$r); }",
            'tested',
        ];
        yield 'a form error, read after an else if' => [
            "\$r = $q; if (!\$r) { \$error['x'] = 1; } else if (\$c) { a(\$r); } else { b(); } \$r->c();",
            'unread',
        ];
        yield 'a form error, an else that is a switch, read after' => [
            "\$r = $q; if (!\$r) { \$error['x'] = 1; } else switch (\$c) { case 1: a(); } \$r->c();",
            'unread',
        ];
        yield 'a form error, an else that is a loop, read after' => [
            "\$r = $q; if (!\$r) { \$error['x'] = 1; } else foreach (\$a as \$x) { b(); } \$r->c();",
            'unread',
        ];
        yield 'a form error in alternative syntax, which is not read as refusing' => [
            "\$r = $q; if (!\$r): \$error['x'] = 1; else: a(\$r); endif;",
            'folded',
        ];
        yield 'a form error, braceless, read after' => ["\$r = $q; if (!\$r) \$error['x'] = 1; \$r->c();", 'unread'];
        yield 'a form error and a die' => ["\$r = $q; if (!\$r) { \$error['x'] = 1; die(); } \$r->c();", 'tested'];
        yield 'a form error, assigned again' => [
            "\$r = $q; if (!\$r) { \$error['x'] = 1; } \$r = b(); \$r->c();",
            'tested',
        ];
        yield 'a form error, assigned again in a branch' => [
            "\$r = $q; if (!\$r) { \$error['x'] = 1; } if (\$c) { \$r = b(); } \$r->c();",
            'unread',
        ];
        yield 'a form error, read after the block' => [
            "if (\$c) { \$r = $q; if (!\$r) { \$error['x'] = 1; } } \$r->c();",
            'unread',
        ];
        yield 'a form error in a loop, read at its top' => [
            "foreach (\$a as \$x) { if (\$r) { \$r->c(); } \$r = $q; if (!\$r) { \$error['x'] = 1; } }",
            'unread',
        ];
        yield 'a form error in a while loop, read at its top' => [
            "while (\$x) { b(\$r); \$r = $q; if (!\$r) { \$error['x'] = 1; } }",
            'unread',
        ];
        yield 'a form error in a do loop, read at its top' => [
            "do { b(\$r); \$r = $q; if (!\$r) { \$error['x'] = 1; } } while (\$x);",
            'unread',
        ];
        yield 'a form error in an alternative-syntax loop, read at its top' => [
            "foreach (\$a as \$x): b(\$r); \$r = $q; if (!\$r) { \$error['x'] = 1; } endforeach;",
            'unread',
        ];
        yield 'a form error in a loop, read only in it before' => [
            "\$r = 1; foreach (\$a as \$x) { \$y = $q; if (!\$y) { \$error['x'] = 1; } }",
            'tested',
        ];
        yield 'a form error, captured by a closure after' => [
            "\$r = $q; if (!\$r) { \$error['x'] = 1; } \$f = function () use (\$r) { return 1; };",
            'unread',
        ];
        yield 'a form error, read in a closure declared after' => [
            "\$r = $q; if (!\$r) { \$error['x'] = 1; } \$f = function (\$r) { return \$r; };",
            'tested',
        ];
        yield 'a bad flash in an else, assigned in the condition, read after' => [
            "if (\$r = $q) { a(); } else { p202_account_flash('bad', 'x'); } \$r->c();",
            'unread',
        ];
        yield 'a bad flash, nothing to read' => [
            "if ($q) { a(); } else { p202_account_flash('bad', 'x'); } \$r->c();",
            'tested',
        ];
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
            $named = $assigned ? $tokens[$start]->text : null;
            if (in_array($condition, ['! Q', '! ( Q )', 'Q === false', 'false === Q'], true)) {
                return self::refusal($tokens, $bodies, $closer + 1, $named, $start)
                    ?? ['folded', 'the branch that tests it for failure refuses nothing at its own level'];
            }
            if (in_array($condition, ['Q', '( Q )', 'Q !== false', 'false !== Q'], true)) {
                $else = self::elseBranch($tokens, $closer + 1);

                return ($else !== null ? self::refusal($tokens, $bodies, $else, $named, $start) : null)
                    ?? ['folded', 'its failure falls through: no else refuses when it answers false'];
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

        return self::firstRead($tokens, $bodies, $call - 2, $close + 1);
    }

    /**
     * How the first appearance of the variable assigned at $variableAt,
     * after $from, in the same function, reads it.
     *
     * @param list<PhpToken> $tokens
     * @param list<array{int, int, string}> $bodies
     * @return array{string, string}
     */
    private static function firstRead(array $tokens, array $bodies, int $variableAt, int $from): array
    {
        $variable = $tokens[$variableAt]->text;
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
                    if (
                        !$tokens[$opener - 1]->is(T_IF)
                        || !self::startsStatement($tokens, $opener - 1)
                        || !self::runsOnEveryPathFrom($tokens, $from, $opener - 1)
                    ) {
                        return ['unread', "$variable is tested for failure on one path only: the test is not an if"
                            . ' of its own that runs whenever the assignment did'];
                    }

                    return self::refusal($tokens, $bodies, $closer + 1, $variable, $variableAt)
                        ?? ['folded', "the branch that tests $variable for failure refuses nothing at its own level"];
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
     * Whether a statement at $at runs on every path from $from: it is not
     * inside a block opened after $from, and no loop body around $from has
     * closed between them (a test after the loop reads only its last turn).
     * Leaving any other block (an if's, a try's) keeps every path.
     *
     * @param list<PhpToken> $tokens
     */
    private static function runsOnEveryPathFrom(array $tokens, int $from, int $at): bool
    {
        $depth = 0;
        $floor = 0;
        $alternative = 0;
        for ($k = $from; $k < $at; $k++) {
            $token = $tokens[$k];
            if (self::opens($token)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth < $floor) {
                    $floor = $depth;
                    if ($token->text === '}' && self::afterALoopHead($tokens, self::opening($tokens, $k))) {
                        return false;
                    }
                }
            } elseif ($token->text === ':' && self::afterALoopHead($tokens, $k)) {
                $alternative++;
            } elseif ($token->is([T_ENDFOR, T_ENDFOREACH, T_ENDWHILE]) && --$alternative < 0) {
                return false;
            }
        }

        return $depth === $floor && $alternative === 0;
    }

    /** @param list<PhpToken> $tokens */
    private static function opening(array $tokens, int $close): int
    {
        $depth = 0;
        for ($k = $close; $k >= 0; $k--) {
            if (in_array($tokens[$k]->text, [')', ']', '}'], true)) {
                $depth++;
            } elseif (self::opens($tokens[$k]) && --$depth === 0) {
                return $k;
            }
        }

        return 0;
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
     * How the failure branch at $at refuses, as a site's kind, or null when
     * it refuses nothing. A branch that ends the request answers tested. One
     * that only says so (files `$error[...]`, flashes 'bad') carries on past
     * the if, so a result with a name is tested only when nothing reads it on
     * that path (CLAUDE.md #22): after the if, to the end of its scope, or
     * from the top of a loop it sits in, on the loop's next turn.
     *
     * @param list<PhpToken> $tokens
     * @param list<array{int, int, string}> $bodies
     * @return array{string, string}|null
     */
    private static function refusal(array $tokens, array $bodies, int $at, ?string $variable, int $assignedAt): ?array
    {
        $how = self::branchRefuses($tokens, $at);
        if ($how === 'none') {
            return null;
        }
        if ($how === 'ends' || $variable === null) {
            return ['tested', ''];
        }
        $read = self::readAfterSaying($tokens, $bodies, $variable, $assignedAt, self::chainEnd($tokens, $at));

        return $read === null
            ? ['tested', '']
            : ['unread', "$variable is read on line $read after a failure that only says so and carries on"];
    }

    /**
     * Whether the branch starting at $at (a block, or one statement) holds,
     * at its own top level, a statement that ends the request ('ends'), only
     * one that says so ('says'), or neither ('none').
     *
     * @param list<PhpToken> $tokens
     */
    private static function branchRefuses(array $tokens, int $at): string
    {
        $braced = ($tokens[$at]->text ?? '') === '{';
        $end = $braced ? self::closing($tokens, $at) : self::statementEnd($tokens, $at);
        $statementStart = true;
        $depth = 0;
        $says = false;
        for ($k = $braced ? $at + 1 : $at; $k < $end; $k++) {
            $token = $tokens[$k];
            if ($depth === 0 && $statementStart) {
                $returned = $token->is(T_RETURN) ? ($tokens[$k + 1] ?? null) : null;
                $returnsAnError = $returned !== null
                    && in_array(SourceScan::callableName($returned->id, $returned->text), self::ERROR_ANSWERS, true)
                    && self::isCall($tokens, $k + 1);
                if (
                    $token->is(T_THROW)
                    || ($token->is(T_EXIT) && (self::isCall($tokens, $k) || ($tokens[$k + 1]->text ?? '') === ';'))
                    || (in_array(SourceScan::callableName($token->id, $token->text), self::REFUSING_CALLS, true)
                        && self::isCall($tokens, $k))
                    || $returnsAnError
                ) {
                    return 'ends';
                }
                if (
                    ($token->is(T_VARIABLE) && $token->text === '$error' && ($tokens[$k + 1]->text ?? '') === '[')
                    || self::flashesBad($tokens, $k)
                ) {
                    $says = true;
                }
            }
            if (self::opens($token)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            }
            $statementStart = $depth === 0 && in_array($token->text, [';', '}'], true);
        }

        return $says ? 'says' : 'none';
    }

    /**
     * Where the if/elseif/else chain whose branch starts at $at ends: the
     * last token of its last branch. A branch whose end cannot be read here
     * (alternative syntax, a braceless try or do) ends where it starts, so
     * what it holds is read as after the chain.
     *
     * @param list<PhpToken> $tokens
     */
    private static function chainEnd(array $tokens, int $at): int
    {
        while (true) {
            $end = self::branchEnd($tokens, $at);
            $next = $tokens[$end + 1] ?? null;
            if ($end > $at && $next !== null && $next->is(T_ELSEIF)) {
                $at = self::closing($tokens, $end + 2) + 1;
                continue;
            }
            if ($end > $at && $next !== null && $next->is(T_ELSE)) {
                return self::branchEnd($tokens, $end + 2);
            }

            return $end;
        }
    }

    /** @param list<PhpToken> $tokens */
    private static function branchEnd(array $tokens, int $at): int
    {
        $first = $tokens[$at] ?? null;
        if ($first === null) {
            return $at;
        }
        if ($first->text === '{') {
            return self::closing($tokens, $at);
        }
        if ($first->is(T_IF)) {
            return self::chainEnd($tokens, self::closing($tokens, $at + 1) + 1);
        }
        if ($first->is([T_FOR, T_FOREACH, T_WHILE, T_SWITCH])) {
            $body = self::closing($tokens, $at + 1) + 1;

            return $first->is(T_SWITCH) ? self::closing($tokens, $body) : self::branchEnd($tokens, $body);
        }
        if ($first->text === ':' || $first->is([T_TRY, T_DO])) {
            return $at;
        }

        return self::statementEnd($tokens, $at);
    }

    /**
     * The line of the first appearance of $variable on the path a failure
     * that only says so goes on to: from the end of its if chain to the end
     * of its scope, stopping at an assignment of the whole variable made
     * directly in a block the path is in, and from the top of the outermost
     * loop around the assignment up to it. Null when there is none.
     *
     * @param list<PhpToken> $tokens
     * @param list<array{int, int, string}> $bodies
     */
    private static function readAfterSaying(
        array $tokens,
        array $bodies,
        string $variable,
        int $assignedAt,
        int $chainEnd
    ): ?int {
        [$scopeStart, $scopeEnd] = self::scopeOf($bodies, $assignedAt, count($tokens));
        $loop = self::loopAround($tokens, $scopeStart, $assignedAt);
        if ($loop !== null) {
            $line = self::appearance($tokens, $variable, $loop, $assignedAt);
            if ($line !== null) {
                return $line;
            }
        }
        $depth = 0;
        $floor = 0;
        for ($k = $chainEnd + 1; $k < $scopeEnd; $k++) {
            if ($tokens[$k]->is(T_FUNCTION)) {
                [$k, $captured] = self::pastFunction($tokens, $k, $variable);
                if ($captured !== null) {
                    return $captured;
                }
                continue;
            }
            $token = $tokens[$k];
            if (self::opens($token)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
                $floor = min($floor, $depth);
            }
            if (!$token->is(T_VARIABLE) || $token->text !== $variable) {
                continue;
            }
            if ($depth === $floor && ($tokens[$k + 1]->text ?? '') === '=' && self::startsStatement($tokens, $k)) {
                return null;
            }

            return $token->line;
        }

        return null;
    }

    /**
     * The line of $variable's first appearance in [$from, $to), outside the
     * functions declared there (a closure's `use` of it counts), or null.
     *
     * @param list<PhpToken> $tokens
     */
    private static function appearance(array $tokens, string $variable, int $from, int $to): ?int
    {
        for ($k = $from; $k < $to; $k++) {
            if ($tokens[$k]->is(T_FUNCTION)) {
                [$k, $captured] = self::pastFunction($tokens, $k, $variable);
                if ($captured !== null) {
                    return $captured;
                }
                continue;
            }
            if ($tokens[$k]->is(T_VARIABLE) && $tokens[$k]->text === $variable) {
                return $tokens[$k]->line;
            }
        }

        return null;
    }

    /**
     * Past a function declared at $k (its parameters are its own, and so is
     * its body): [the index of its body's `}`, the line of a `use` of
     * $variable, which captures it, or null].
     *
     * @param list<PhpToken> $tokens
     * @return array{int, int|null}
     */
    private static function pastFunction(array $tokens, int $k, string $variable): array
    {
        $count = count($tokens);
        $depth = 0;
        $captured = null;
        for ($j = $k + 1; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
            } elseif ($depth === 0 && $text === ';') {
                // No body: a declaration only.
                return [$j, null];
            } elseif ($depth === 0 && $text === '{') {
                return [self::closing($tokens, $j), $captured];
            } elseif (
                $captured === null
                && $tokens[$j]->is(T_VARIABLE)
                && $text === $variable
                && self::inUse($tokens, $k, $j)
            ) {
                $captured = $tokens[$j]->line;
            }
        }

        return [$count, $captured];
    }

    /**
     * Whether the variable at $j, in the head of the function declared at
     * $k, is in its `use (...)`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function inUse(array $tokens, int $k, int $j): bool
    {
        for ($u = $k; $u < $j; $u++) {
            if ($tokens[$u]->is(T_USE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where the body of the outermost loop around $at in its scope opens, or
     * null when it is in none: a `{` after `for (…)`, `foreach (…)`,
     * `while (…)` or `do`, or the `:` of their alternative syntax.
     *
     * @param list<PhpToken> $tokens
     */
    private static function loopAround(array $tokens, int $scopeStart, int $at): ?int
    {
        $braces = [];
        $alternative = [];
        for ($k = $scopeStart + 1; $k < $at; $k++) {
            $token = $tokens[$k];
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $braces[] = [$k, $token->text === '{' && self::afterALoopHead($tokens, $k)];
            } elseif ($token->text === '}') {
                array_pop($braces);
            } elseif ($token->text === ':' && self::afterALoopHead($tokens, $k)) {
                $alternative[] = $k;
            } elseif ($token->is([T_ENDFOR, T_ENDFOREACH, T_ENDWHILE])) {
                array_pop($alternative);
            }
        }
        $opens = array_merge(array_column(array_filter($braces, static fn (array $b): bool => $b[1]), 0), $alternative);

        return $opens === [] ? null : min($opens);
    }

    /**
     * Whether the token at $k follows a loop's head: `do`, or the `)` that
     * closes `for (`, `foreach (` or `while (`.
     *
     * @param list<PhpToken> $tokens
     */
    private static function afterALoopHead(array $tokens, int $k): bool
    {
        $prev = $tokens[$k - 1] ?? null;
        if ($prev === null) {
            return false;
        }
        if ($prev->is(T_DO)) {
            return true;
        }
        if ($prev->text !== ')') {
            return false;
        }
        $depth = 0;
        for ($j = $k - 1; $j >= 0; $j--) {
            if ($tokens[$j]->text === ')') {
                $depth++;
            } elseif ($tokens[$j]->text === '(' && --$depth === 0) {
                return ($tokens[$j - 1] ?? null)?->is([T_FOR, T_FOREACH, T_WHILE]) ?? false;
            }
        }

        return false;
    }

    /**
     * Whether the name at $k is called: `name(` with arguments, not the
     * first-class callable `name(...)`, which makes a closure and goes on.
     *
     * @param list<PhpToken> $tokens
     */
    private static function isCall(array $tokens, int $k): bool
    {
        return ($tokens[$k + 1]->text ?? '') === '('
            && !(($tokens[$k + 2] ?? null)?->is(T_ELLIPSIS) && ($tokens[$k + 3]->text ?? '') === ')');
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
            || $prev->is([T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_INLINE_HTML])
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
