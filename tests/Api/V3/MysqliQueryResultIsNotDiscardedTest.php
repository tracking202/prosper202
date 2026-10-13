<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * No `_mysqli_query()` is a statement on its own, its answer thrown away.
 *
 * The admin pages' _mysqli_query() (202-config/functions.php) answers false
 * when a statement fails, and until 1.9.78 it said nothing else, so a write
 * whose call stood alone failed without a trace: the remember-me sign-in
 * stored no key and still set its cookie, which every later visit refused,
 * and a failed read of the remember-me secret minted a new one over the
 * stored key, signing every remembered browser of the account out. It logs
 * each failure now (p202LogFailedQuery()), and this holds every caller to
 * looking at what it answers (CLAUDE.md #1).
 *
 * connect2.php's own _mysqli_query() ends the request on a failure
 * (record_mysql_error()), so a call in that file needs no check of its own.
 * A call whose failure is harmless is listed in TOLERATED, by function, with
 * why; the list only shrinks. This floor sees the discarded statement only:
 * a result assigned and then never tested is not seen.
 */
final class MysqliQueryResultIsNotDiscardedTest extends TestCase
{
    /** Files whose calls resolve to connect2.php's _mysqli_query(), which ends the request on a failure. */
    private const ENDS_THE_REQUEST = ['202-config/connect2.php'];

    /**
     * file::function => [the calls it discards, why a failure there may go
     * unanswered]. The count is held exactly: a call added beside the one
     * weighed is not the one whose failure was weighed.
     *
     * @var array<string, array{int, string}>
     */
    private const TOLERATED = [
        '202-config/functions-auth.php::is_valid_api_key' => [
            1,
            'Copies the licence key read for the account into the session user\'s row; a failure leaves that row'
            . ' as it was and the next check reads the key where it was found.',
        ],
        '202-config/functions-auth.php::delete_old_auth_hash' => [
            1,
            'Housekeeping: the remember-me read refuses an expired key whether or not it was deleted.',
        ],
        '202-config/functions-tracking202.php::createPublisherIds' => [
            1,
            'Run by the installer and by the 1.9.47 upgrade rung, which is frozen (CLAUDE.md #33): a user left'
            . ' without a publisher id is filled by the next run.',
        ],
    ];

    /** @return array<string, list<string>> file => "line (function)" of each discarded call */
    private static function discardedCalls(): array
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (in_array($path, self::ENDS_THE_REQUEST, true)) {
                continue;
            }
            $tolerated = [];
            foreach (SourceScan::uncheckedCallStatements($source, [], ['_mysqli_query']) as $line) {
                $function = self::functionAt($source, $line);
                if (isset(self::TOLERATED[$path . '::' . $function])) {
                    $tolerated[$function][] = $line;
                    continue;
                }
                $found[$path][] = "$line ($function)";
            }
            foreach ($tolerated as $function => $lines) {
                $listed = self::TOLERATED[$path . '::' . $function][0];
                if (count($lines) > $listed) {
                    $found[$path][] = implode(', ', $lines) . " ($function: " . count($lines)
                        . " discarded, TOLERATED weighs $listed)";
                }
            }
        }

        return $found;
    }

    /**
     * The innermost named function around the first _mysqli_query call on
     * $line, or '' at file scope (a closure is read as its enclosing function).
     */
    private static function functionAt(string $source, int $line): string
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->isIgnorable()
        ));
        $stack = [];
        $depth = 0;
        $pending = null;
        foreach ($tokens as $i => $token) {
            $isTheCall = $token->line === $line && $token->is(T_STRING) && $token->text === '_mysqli_query';
            if ($token->line > $line || $isTheCall) {
                break;
            }
            if ($token->is(T_FUNCTION)) {
                $next = $tokens[$i + 1] ?? null;
                $pending = ($next !== null && $next->is(T_STRING)) ? $next->text : '{closure}';
            } elseif ($token->text === ';' && $pending !== null) {
                // A declaration with no body (abstract, interface), or `use function`.
                $pending = null;
            } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($pending !== null && $token->text === '{') {
                    $stack[] = [$pending, $depth];
                    $pending = null;
                }
            } elseif ($token->text === '}') {
                if ($stack !== [] && end($stack)[1] === $depth) {
                    array_pop($stack);
                }
                $depth--;
            }
        }
        foreach (array_reverse($stack) as [$name]) {
            if ($name !== '{closure}') {
                return $name;
            }
        }

        return '';
    }

    public function testNoMysqliQueryResultIsThrownAway(): void
    {
        $found = self::discardedCalls();

        $this->assertSame([], $found, sprintf(
            "These _mysqli_query() calls are statements on their own, their answer thrown away:\n%s\n"
            . 'On the admin pages it answers false when the statement fails. Test it and act on the failure'
            . ' (refuse, or say what was not done); list it in TOLERATED only when a failure is harmless.',
            implode("\n", array_map(
                static fn (string $f, array $sites): string => "  $f: line " . implode(', ', $sites),
                array_keys($found),
                $found
            ))
        ));
    }

    public function testEveryToleratedCallIsStillThere(): void
    {
        $files = SourceScan::phpFiles();
        foreach (self::TOLERATED as $id => [$count, $why]) {
            [$file, $function] = explode('::', $id);
            $this->assertArrayHasKey($file, $files, "TOLERATED lists $id, whose file is gone");
            $lines = array_filter(
                SourceScan::uncheckedCallStatements($files[$file], [], ['_mysqli_query']),
                static fn (int $line): bool => self::functionAt($files[$file], $line) === $function
            );
            $this->assertCount(
                $count,
                $lines,
                "TOLERATED weighs $count discarded _mysqli_query() in $id ($why): remove it, or set the count"
            );
        }
    }

    /** @return array<string, array{string, list<string>}> */
    public static function shapes(): array
    {
        return [
            'a call alone' => ["<?php function f() { _mysqli_query(\$sql); }", ['f']],
            'with the connection' => ["<?php function f() { _mysqli_query(\$db, \$sql); }", ['f']],
            'in a method' => [
                "<?php class C { public static function m() { if (\$x) { _mysqli_query(\$sql); } } }",
                ['m'],
            ],
            'in a closure in a function' => [
                "<?php function f() { \$g = function () { _mysqli_query(\$sql); }; }",
                ['f'],
            ],
            'at file scope' => ["<?php _mysqli_query(\$sql);", ['']],
            'assigned' => ["<?php function f() { \$r = _mysqli_query(\$sql); }", []],
            'tested' => ["<?php function f() { if (_mysqli_query(\$sql) === false) { throw new E(); } }", []],
            'returned' => ["<?php function f() { return _mysqli_query(\$sql); }", []],
            'after an abstract method' => [
                "<?php abstract class A { abstract function a(); function b() { _mysqli_query(\$sql); } }",
                ['b'],
            ],
        ];
    }

    /**
     * @dataProvider shapes
     * @param list<string> $functions
     */
    public function testTheScanFindsEachShape(string $source, array $functions): void
    {
        $found = array_map(
            static fn (int $line): string => self::functionAt($source, $line),
            SourceScan::uncheckedCallStatements($source, [], ['_mysqli_query'])
        );

        $this->assertSame($functions, $found);
    }
}
