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
 * third argument.
 */
final class ErrorPageAfterRollbackNamesTheFailureTest extends TestCase
{
    /** @return list<string> file:line of each call that names no failure after a rollback */
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
            if (self::argumentCount($tokens, $i + 1) < 3 && self::rolledBackBefore($tokens, $i)) {
                $found[] = "$path:{$token->line}";
            }
        }

        return $found;
    }

    /** @param list<\PhpToken> $tokens */
    private static function argumentCount(array $tokens, int $open): int
    {
        $depth = 0;
        $commas = 0;
        for ($j = $open; $j < count($tokens); $j++) {
            $text = $tokens[$j]->text;
            if (in_array($text, ['(', '[', '{'], true) || $tokens[$j]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return $j === $open + 1 ? 0 : $commas + 1;
                }
            } elseif ($depth === 1 && $text === ',') {
                $commas++;
            }
        }

        return $commas + 1;
    }

    /**
     * A rollback before the call in its block or a block around it, up to
     * the function it is in; one inside a block that closed before it is
     * another path.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function rolledBackBefore(array $tokens, int $call): bool
    {
        $depth = 0;
        for ($k = $call - 1; $k >= 0; $k--) {
            $token = $tokens[$k];
            if ($token->text === '}') {
                $depth++;
            } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
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

    public function testEveryCallAfterARollbackNamesTheFailure(): void
    {
        $found = [];
        $calls = 0;
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_contains($source, 'record_mysql_error') || !str_contains(strtolower($source), 'rollback')) {
                continue;
            }
            $calls += substr_count($source, 'record_mysql_error(');
            array_push($found, ...self::unnamed($path, $source));
        }

        self::assertGreaterThan(5, $calls, 'the scan read the files that roll back and record');
        self::assertSame([], $found, "These record_mysql_error() calls follow a rollback, which cleared the error"
            . " they would record. Keep \$db->error before the rollback and pass it as the third argument:\n"
            . implode("\n", $found));
    }

    /** @return array<string, array{string, list<int>}> */
    public static function shapes(): array
    {
        return [
            'rollback, then the page' => ["<?php\n\$db->rollback();\nrecord_mysql_error(\$sql);\n", [3]],
            'with the connection' => ["<?php\n\$db->rollback();\nrecord_mysql_error(\$db, \$sql);\n", [3]],
            'the failure named' => ["<?php\n\$db->rollback();\nrecord_mysql_error(\$sql, null, \$failed);\n", []],
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
