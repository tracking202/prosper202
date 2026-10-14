<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A rollback clears the connection's error, and record_mysql_error() reads
 * the error from the connection: called after one, it filed a blank error.
 * Get Links rolled back before five of its calls, and two were fixed before
 * the other three were found. A call reached after a rollback (in its own
 * block or one around it, before it) names the failure it caught, as its
 * third argument; so does a call in a catch block, whatever its try did,
 * because a rollback made inside a call (Connection::transaction() rolls
 * back before it rethrows) names nothing a scan can see. A third argument
 * that is a literal null, false or empty string names nothing either: the
 * page reads it as "read the connection", which the rollback has cleared.
 */
final class ErrorPageAfterRollbackNamesTheFailureTest extends TestCase
{
    /** @return list<string> file:line of each call that names no failure after a rollback or in a catch */
    private static function unnamed(string $path, string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->isIgnorable()
        ));
        $found = [];
        $notACall = [T_FUNCTION, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW];
        foreach ($tokens as $i => $token) {
            $isCall = SourceScan::callableName($token->id, $token->text) === 'record_mysql_error'
                && ($tokens[$i + 1]->text ?? '') === '('
                && !(($tokens[$i - 1] ?? null)?->is($notACall) ?? false);
            if (!$isCall) {
                continue;
            }
            $failure = self::argument($tokens, $i + 1, 2);
            if (($failure === null || self::namesNothing($failure)) && self::errorMayBeCleared($tokens, $i)) {
                $found[] = "$path:{$token->line}";
            }
        }

        return $found;
    }

    /**
     * The tokens of the call's argument at $index (0 for the first), or null
     * when the call has no such argument.
     *
     * @param list<\PhpToken> $tokens
     * @return list<\PhpToken>|null
     */
    private static function argument(array $tokens, int $open, int $index): ?array
    {
        $depth = 0;
        $n = 0;
        $argument = [];
        for ($j = $open; $j < count($tokens); $j++) {
            $token = $tokens[$j];
            $opens = in_array($token->text, ['(', '[', '{'], true)
                || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]);
            if ($opens) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return $n === $index && $argument !== [] ? $argument : null;
                }
            } elseif ($depth === 1 && $token->text === ',') {
                if ($n === $index) {
                    return $argument;
                }
                $n++;
                continue;
            }
            if ($n === $index) {
                $argument[] = $token;
            }
        }

        return null;
    }

    /**
     * Whether an argument is a literal that names no failure: null, false or
     * an empty string, parenthesized or passed by name.
     *
     * @param list<\PhpToken> $argument
     */
    private static function namesNothing(array $argument): bool
    {
        if (count($argument) >= 2 && $argument[0]->is(T_STRING) && $argument[1]->text === ':') {
            $argument = array_slice($argument, 2);
        }
        while (count($argument) >= 2 && $argument[0]->text === '(' && end($argument)->text === ')') {
            $argument = array_slice($argument, 1, -1);
        }

        return count($argument) === 1
            && in_array(strtolower($argument[0]->text), ['null', 'false', "''", '""'], true);
    }

    /**
     * A rollback before the call in its block or a block around it, or a
     * catch block around it, up to the function it is in; a rollback inside
     * a block that closed before it is another path.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function errorMayBeCleared(array $tokens, int $call): bool
    {
        $depth = 0;
        for ($k = $call - 1; $k >= 0; $k--) {
            $token = $tokens[$k];
            if ($token->text === '}') {
                $depth++;
            } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                if ($depth === 0 && self::opensACatch($tokens, $k)) {
                    return true;
                }
                $depth = max(0, $depth - 1);
            } elseif ($depth === 0 && $token->is([T_FUNCTION, T_FN])) {
                return false;
            } elseif ($depth === 0 && ($tokens[$k + 1]->text ?? '') === '(') {
                $name = SourceScan::callableName($token->id, $token->text);
                $method = ($tokens[$k - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]) ?? false;
                if (($name === 'rollback' && $method) || $name === 'mysqli_rollback') {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<\PhpToken> $tokens */
    private static function opensACatch(array $tokens, int $brace): bool
    {
        if (($tokens[$brace - 1]->text ?? '') !== ')') {
            return false;
        }
        $depth = 0;
        for ($k = $brace - 1; $k >= 0; $k--) {
            $depth += match ($tokens[$k]->text) {
                ')' => 1,
                '(' => -1,
                default => 0,
            };
            if ($depth === 0) {
                return ($tokens[$k - 1] ?? null)?->is(T_CATCH) ?? false;
            }
        }

        return false;
    }

    public function testEveryCallAfterARollbackNamesTheFailure(): void
    {
        $found = [];
        $calls = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_contains(strtolower($source), 'record_mysql_error')) {
                continue;
            }
            $calls += substr_count($source, 'record_mysql_error(');
            array_push($found, ...self::unnamed($path, $source));
        }

        self::assertGreaterThan(5, $calls, 'the scan read the files that record');
        self::assertSame([], $found, "These record_mysql_error() calls follow a rollback or sit in a catch block,"
            . " where the error they would record may be cleared. Pass the failure (\$db->error kept before the"
            . " rollback, or the exception's message) as the third argument:\n" . implode("\n", $found));
    }

    /** @return array<string, array{string, list<int>}> */
    public static function shapes(): array
    {
        return [
            'rollback, then the page' => ["<?php\n\$db->rollback();\nrecord_mysql_error(\$sql);\n", [3]],
            'with the connection' => ["<?php\n\$db->rollback();\nrecord_mysql_error(\$db, \$sql);\n", [3]],
            'the failure named' => ["<?php\n\$db->rollback();\nrecord_mysql_error(\$sql, null, \$failed);\n", []],
            'a null failure' => ["<?php\n\$db->rollback();\nrecord_mysql_error(\$db, \$sql, null);\n", [3]],
            'an empty failure' => ["<?php\n\$db->rollback();\nrecord_mysql_error(\$db, \$sql, '');\n", [3]],
            'a parenthesized null failure' => [
                "<?php\n\$db->rollback();\nrecord_mysql_error(\$db, \$sql, (NULL));\n",
                [3],
            ],
            'a false failure by name' => [
                "<?php\n\$db->rollback();\nrecord_mysql_error(\$db, \$sql, error: false);\n",
                [3],
            ],
            'a trailing comma' => ["<?php\n\$db->rollback();\nrecord_mysql_error(\$db, \$sql,);\n", [3]],
            'a failure built from the exception' => [
                "<?php\ntry {\nf();\n} catch (E \$e) {\nrecord_mysql_error(\$db, \$s, 'x: ' . \$e->getMessage());\n}\n",
                [],
            ],
            'in an if around the rollback' => [
                "<?php\nif (!\$ok) {\n\$db->rollback();\nif (\$x) {\nrecord_mysql_error(\$sql);\n}\n}\n",
                [5],
            ],
            'procedural rollback' => ["<?php\nmysqli_rollback(\$db);\nrecord_mysql_error(\$db);\n", [3]],
            'a rollback in a block already closed' => [
                "<?php\nif (\$a) {\n\$db->rollback();\nreturn;\n}\nrecord_mysql_error(\$sql);\n",
                [],
            ],
            'a rollback in another function' => [
                "<?php\nfunction a() {\n\$db->rollback();\n}\nfunction b() {\nrecord_mysql_error(\$sql);\n}\n",
                [],
            ],
            'no rollback' => ["<?php\n\$db->query(\$sql) or record_mysql_error(\$db, \$sql);\n", []],
            'in a catch' => [
                "<?php\ntry {\n\$c->transaction(\$f);\n} catch (\\Throwable \$e) {\nrecord_mysql_error(\$db);\n}\n",
                [5],
            ],
            'in an if in a catch' => [
                "<?php\ntry {\nf();\n} catch (E \$e) {\nif (\$x) {\nrecord_mysql_error(\$db, \$s);\n}\n}\n",
                [6],
            ],
            'in a catch, named' => [
                "<?php\ntry {\nf();\n} catch (E \$e) {\nrecord_mysql_error(\$db, \$s, \$e->getMessage());\n}\n",
                [],
            ],
            'after a catch that closed' => [
                "<?php\ntry {\nf();\n} catch (E \$e) {\nlog(\$e);\n}\n"
                    . "\$db->query(\$s) or record_mysql_error(\$db, \$s);\n",
                [],
            ],
        ];
    }

    /**
     * @dataProvider shapes
     * @param list<int> $lines
     */
    public function testTheScanFindsEachShape(string $source, array $lines): void
    {
        $found = array_map(
            static fn (string $site): int => (int) substr($site, strrpos($site, ':') + 1),
            self::unnamed('x.php', $source)
        );

        self::assertSame($lines, $found);
    }
}
