<?php

declare(strict_types=1);

namespace Tests\Redirect;

use PHPUnit\Framework\TestCase;

/**
 * Under declare(strict_types=1), mktime() refuses a string with a TypeError,
 * and date() always returns one. The click endpoints build the day's click
 * time as `mktime(12, 0, 0, $today_month, $today_day, $today_year)` from
 * three date() calls: dl.php and record_simple.php cast them to int, and
 * off.php did not, so every advanced landing page's campaign link
 * (go.php?acip=, off.php?acip=) answered 500 — found when the landing-page
 * code the API hands out was followed end to end. The same shape had been
 * fixed in the two siblings one at a time.
 *
 * So, over every strict-typed PHP file in the tree: no mktime() or
 * gmmktime() argument is a date() call, or a variable that the file
 * assigns straight from date() without a cast (`$m = date('n');`). It reads
 * that one shape — the one that shipped — and nothing wider: a value that
 * reaches mktime() through a function, an array or another variable is not
 * followed, and a variable cast after its assignment is still reported (the
 * loud direction: rewrite the assignment with the cast).
 */
final class StrictTypesMktimeArgumentsTest extends TestCase
{
    private const FUNCTIONS = ['mktime', 'gmmktime'];

    public function testNoDateStringReachesMktimeInAStrictFile(): void
    {
        $root = dirname(__DIR__, 2);
        $files = 0;
        $calls = 0;
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);
            if (!str_ends_with($path, '.php') || preg_match('#^(vendor|node_modules|tests|\.git|\.claude)/#', $path) === 1) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, 'strict_types=1') || !preg_match('/\b(gm)?mktime\s*\(/i', $source)) {
                continue;
            }
            $files++;
            foreach (self::findings($source, $calls) as $line => $what) {
                $found[] = "$path:$line $what";
            }
        }
        self::assertGreaterThan(5, $files, 'the scan found the strict-typed files that call mktime()');
        self::assertGreaterThan(10, $calls, 'and read their calls');
        self::assertSame([], $found, 'a date() string reaches mktime() in a strict_types file (a TypeError at run time); cast it to int');
    }

    public function testTheScanSeesTheShapeThatShipped(): void
    {
        $calls = 0;
        $planted = <<<'PHP'
<?php
declare(strict_types=1);
$today_day = date('j', time());
$today_month = (int) date('n', time());
$y = date('Y');
$click_time = mktime(12, 0, 0, $today_month, $today_day, $y);
$direct = gmmktime(0, 0, 0, date('n'), 1, 2026);
$fine = mktime(0, 0, 0, (int) date('n'), 1, (int) $y);
$method = $calendar->mktime($today_day);
PHP;
        self::assertSame(
            [6 => 'argument 5 is $today_day (date() at line 3); argument 6 is $y (date() at line 5)', 7 => 'argument 4 is date(...)'],
            self::findings($planted, $calls)
        );
        self::assertSame(3, $calls, 'a method named mktime is not the function');
    }

    /**
     * @return array<int, string> line => what reaches the call
     */
    private static function findings(string $source, int &$calls): array
    {
        $tokens = array_values(array_filter(\PhpToken::tokenize($source), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        $n = count($tokens);

        // Variables assigned straight from date(): `$v = date(`.
        $fromDate = [];
        for ($i = 0; $i + 3 < $n; $i++) {
            if ($tokens[$i]->id === T_VARIABLE && $tokens[$i + 1]->text === '=' && self::isCall($tokens, $i + 2, 'date')) {
                $fromDate[$tokens[$i]->text] ??= $tokens[$i]->line;
            }
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $name = strtolower(ltrim($tokens[$i]->text, '\\'));
            if (!in_array($name, self::FUNCTIONS, true) || ($tokens[$i + 1]->text ?? '') !== '(') {
                continue;
            }
            $before = $tokens[$i - 1]->text ?? '';
            if (in_array($before, ['->', '?->', '::', 'function', 'new'], true)) {
                continue;
            }
            $calls++;
            $problems = [];
            foreach (self::arguments($tokens, $i + 1) as $index => $argument) {
                if (count($argument) === 1 && $argument[0]->id === T_VARIABLE && isset($fromDate[$argument[0]->text])) {
                    $problems[] = 'argument ' . ($index + 1) . ' is ' . $argument[0]->text . ' (date() at line ' . $fromDate[$argument[0]->text] . ')';
                } elseif (self::isCall($argument, 0, 'date')) {
                    $problems[] = 'argument ' . ($index + 1) . ' is date(...)';
                }
            }
            if ($problems !== []) {
                $out[$tokens[$i]->line] = implode('; ', $problems);
            }
        }

        return $out;
    }

    /** @param list<\PhpToken> $tokens */
    private static function isCall(array $tokens, int $at, string $function): bool
    {
        return isset($tokens[$at], $tokens[$at + 1])
            && in_array($tokens[$at]->id, [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            && strtolower(ltrim($tokens[$at]->text, '\\')) === $function
            && $tokens[$at + 1]->text === '(';
    }

    /**
     * The argument token lists of the call whose '(' is at $open.
     *
     * @param list<\PhpToken> $tokens
     * @return list<list<\PhpToken>>
     */
    private static function arguments(array $tokens, int $open): array
    {
        $args = [[]];
        $depth = 0;
        for ($i = $open; $i < count($tokens); $i++) {
            $text = $tokens[$i]->text;
            if (in_array($text, ['(', '[', '{'], true) || $tokens[$i]->id === T_CURLY_OPEN || $tokens[$i]->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $args[] = [];
                continue;
            }
            $args[count($args) - 1][] = $tokens[$i];
        }

        return $args === [[]] ? [] : $args;
    }
}
