<?php

declare(strict_types=1);

namespace Tests\Standalone;

use PHPUnit\Framework\TestCase;
use Tests\Support\SourceScan;

/**
 * A page that names its escaper `$e` (`$e = static fn (...) => htmlspecialchars(...)`)
 * never writes `$e` again at the top level of the file after that: a
 * `catch (\Throwable $e)` there replaces the escaper with the exception,
 * and the page then fails with "Object of type ... is not callable" at its
 * first `$e(...)` (Account › Settings did, whenever the GeoIP row or the
 * egress proxy setting could not be read). Read as tokens: a write is
 * `$e =`, `catch (... $e)`, `as $e` or `&$e` outside a function or closure
 * body.
 */
final class PageEscaperIsNotOverwrittenTest extends TestCase
{
    public function testNoPageOverwritesItsEscaper(): void
    {
        $pages = 0;
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            $line = self::escaperLine($source);
            if ($line === null) {
                continue;
            }
            $pages++;
            foreach (self::writesAfter($source, $line) as $at) {
                $found[] = "$path:$at";
            }
        }
        self::assertGreaterThan(20, $pages, 'the scan found the pages that name an escaper');
        self::assertSame([], $found, 'these lines write $e after the page made it its escaper; name the exception otherwise');
    }

    public function testTheScanSeesEveryWrite(): void
    {
        $source = "<?php\n\$e = static fn (mixed \$v): string => htmlspecialchars((string) \$v);\n"
            . "try { x(); } catch (\\Throwable \$e) { }\n"
            . "foreach (\$a as \$e) {}\n"
            . "\$f = function () { try {} catch (\\Exception \$e) {} \$e = 1; };\n"
            . "\$e = 2;\n";
        self::assertSame(2, self::escaperLine($source));
        self::assertSame([3, 4, 6], self::writesAfter($source, 2));
    }

    private static function escaperLine(string $source): ?int
    {
        return preg_match('/^\s*\$e\s*=\s*(?:static\s+)?fn\b[^\n]*htmlspecialchars/m', $source, $m, PREG_OFFSET_CAPTURE) === 1
            ? substr_count(substr($source, 0, $m[0][1]), "\n") + 1 + (str_starts_with($m[0][0], "\n") ? 1 : 0)
            : null;
    }

    /** @return list<int> the lines after $after that write $e outside a function body */
    private static function writesAfter(string $source, int $after): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        $lines = [];
        $depth = 0;
        $bodies = [];
        $pendingFunction = false;
        foreach ($tokens as $i => $t) {
            if ($t->is([T_FUNCTION])) {
                $pendingFunction = true;
            }
            if ($t->text === '{' || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                if ($pendingFunction) {
                    $bodies[] = $depth;
                    $pendingFunction = false;
                }
            } elseif ($t->text === '}') {
                if ($bodies !== [] && end($bodies) === $depth) {
                    array_pop($bodies);
                }
                $depth--;
            }
            if ($t->text !== '$e' || $t->line <= $after || $bodies !== []) {
                continue;
            }
            $next = $tokens[$i + 1]->text ?? '';
            $prev = $tokens[$i - 1] ?? null;
            $prev2 = $tokens[$i - 2] ?? null;
            $write = $next === '='
                || ($next === ')' && $prev !== null && ($prev->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED]) || $prev->text === '|')
                    && self::inCatch($tokens, $i))
                || ($prev !== null && $prev->is(T_AS))
                || ($prev !== null && $prev->text === '&' && $prev2 !== null && $prev2->is(T_AS));
            if ($write) {
                $lines[] = $t->line;
            }
        }

        return $lines;
    }

    /** @param list<\PhpToken> $tokens */
    private static function inCatch(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0 && $j > $i - 12; $j--) {
            if ($tokens[$j]->is(T_CATCH)) {
                return true;
            }
            if ($tokens[$j]->text === ';' || $tokens[$j]->text === '{') {
                return false;
            }
        }

        return false;
    }
}
