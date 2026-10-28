<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;

/**
 * The Customer LTV pages read no request value through a conversion that
 * answers for a value it could not read (CLAUDE.md #4): `(string)` makes a
 * list "Array", `empty()` makes "0" absent and "false" present, and `(bool)`
 * makes "false" true. ForbidNumericCastOfRequestParamRule holds `(int)` and
 * `(float)` everywhere; this holds the rest on these pages, which read their
 * values through ltv_helpers.php's p202_ltv_posted_*() and refuse in their
 * own words (Companies' merge read `12abc` as company 12, a name sent as a
 * list was stored as "Array").
 *
 * Read from tokens: a superglobal ($_POST, $_GET, $_REQUEST, $_COOKIE) whose
 * nearest token before it, past any `(`, is one of those casts, `empty`,
 * `strval` or `boolval`. A value copied out of the superglobal first and
 * converted after is not seen. The rest of the tree is not held: outside
 * these pages `(string)` of a superglobal is read at about 360 sites.
 */
final class LtvPagesReadPostedValuesExactlyTest extends TestCase
{
    private const SUPERGLOBALS = ['$_POST', '$_GET', '$_REQUEST', '$_COOKIE'];

    private const LOSSY = [T_STRING_CAST => '(string)', T_BOOL_CAST => '(bool)', T_EMPTY => 'empty()'];

    private const LOSSY_CALLS = ['strval', 'boolval'];

    public function testNoLtvPageConvertsARequestValueItCouldNotRead(): void
    {
        $root = dirname(__DIR__, 2);
        $pages = array_merge(
            glob($root . '/tracking202/ajax/ltv_*.php') ?: [],
            [$root . '/tracking202/ajax/sort_ltv.php'],
            glob($root . '/tracking202/analyze/ltv*.php') ?: []
        );
        self::assertGreaterThanOrEqual(14, count($pages), 'the walk found the LTV pages');

        $found = [];
        $readers = 0;
        foreach ($pages as $path) {
            $hits = self::lossyReads((string) file_get_contents($path));
            $readers += $hits['reads'] > 0 ? 1 : 0;
            foreach ($hits['lossy'] as [$line, $how]) {
                $found[] = substr($path, strlen($root) + 1) . ':' . $line . ' ' . $how;
            }
        }
        self::assertGreaterThanOrEqual(8, $readers, 'the scan saw the pages that read the request');
        self::assertSame([], $found, "These LTV page reads convert a request value they could not read:\n  "
            . implode("\n  ", $found)
            . "\nRead it with p202_ltv_posted_text(), _id(), _choice() or _group() (tracking202/ajax/ltv_helpers.php)"
            . ' and show the refusal.');
    }

    /** @dataProvider shapes */
    public function testTheScanReadsEachShape(string $code, int $lossy): void
    {
        self::assertCount($lossy, self::lossyReads("<?php\n" . $code . "\n")['lossy'], $code);
    }

    /** @return array<string, array{string, int}> */
    public static function shapes(): array
    {
        return [
            '(string) with a default' => ["\$a = (string) (\$_POST['a'] ?? '');", 1],
            '(string) bare' => ["\$a = (string) \$_GET['a'];", 1],
            '(string) of a nested element' => ["\$a = (string) (\$_POST['crm']['city'] ?? '');", 1],
            '!empty()' => ["if (!empty(\$_POST['flag'])) {}", 1],
            'empty() of $_REQUEST' => ["if (empty(\$_REQUEST['a'])) {}", 1],
            '(bool)' => ["\$a = (bool) (\$_COOKIE['a'] ?? false);", 1],
            'strval()' => ["\$a = strval(\$_POST['a']);", 1],
            '\\boolval()' => ["\$a = \\boolval(\$_POST['a']);", 1],
            'trim((string) …)' => ["\$a = trim((string) (\$_POST['q'] ?? ''));", 1],
            'two in one statement' => ["\$a = [(string) \$_POST['a'], !empty(\$_GET['b'])];", 2],
            'a helper' => ["\$a = p202_ltv_posted_text(\$_POST['a'] ?? null, 'a');", 0],
            'isset()' => ["if (isset(\$_POST['a'])) {}", 0],
            'is_string()' => ["\$a = is_string(\$_POST['a'] ?? null);", 0],
            'a cast of something else' => ["\$a = (string) (\$row['a'] ?? '');", 0],
            'a cast of a call on the value' => ["\$a = (string) count(\$_POST['a']);", 0],
            'the read in a comment' => ["// (string) \$_POST['a']", 0],
            'a method named strval' => ["\$a = \$x->strval(\$_POST['a']);", 0],
        ];
    }

    /** @return array{reads: int, lossy: list<array{int, string}>} */
    private static function lossyReads(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $reads = 0;
        $lossy = [];
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_VARIABLE || !in_array($token[1], self::SUPERGLOBALS, true)) {
                continue;
            }
            $reads++;
            $j = $i - 1;
            while ($j >= 0 && $tokens[$j] === '(') {
                $j--;
            }
            $before = $tokens[$j] ?? null;
            if (is_array($before) && isset(self::LOSSY[$before[0]])) {
                $lossy[] = [$token[2], self::LOSSY[$before[0]] . ' of ' . $token[1]];
                continue;
            }
            $previous = $tokens[$j - 1] ?? null;
            if (
                $j < $i - 1
                && is_array($before)
                && in_array($before[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
                && in_array(strtolower(ltrim($before[1], '\\')), self::LOSSY_CALLS, true)
                && !(is_array($previous)
                    && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true))
            ) {
                $lossy[] = [$token[2], strtolower(ltrim($before[1], '\\')) . '() of ' . $token[1]];
            }
        }

        return ['reads' => $reads, 'lossy' => $lossy];
    }
}
