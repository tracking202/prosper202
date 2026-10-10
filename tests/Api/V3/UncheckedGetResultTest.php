<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * `$stmt->get_result()` and `$stmt->store_result()` answer false when they
 * fail, and false is indistinguishable from a legitimate empty answer
 * (CLAUDE.md #1): get_result()'s false read as a result set is "no rows" — a
 * record reported not found, a list reported empty, a batch loop that exits
 * as though done — and store_result()'s leaves num_rows at 0, "not found".
 * Under the mysqli error mode connect.php sets (STRICT alone) the call
 * returns false rather than throwing, and `->get_result()->fetch_assoc()`
 * then raises an \Error that names nothing about the database.
 *
 * UncheckedExecuteTest holds execute() to a checked result; this is its
 * sibling for the two calls that read one. A site is checked when, read from
 * the tokens:
 *
 *  - get_result(): its value is assigned to a variable,
 *        $result = $stmt->get_result();
 *    and the very next statement is an `if` whose condition is exactly a
 *    failure test of that variable (`$result === false`, `false === $result`,
 *    `!$result`, `!$result instanceof \mysqli_result`) and whose block throws
 *    — a failure has to be refused, not turned into an empty answer:
 *        if ($result === false) { $stmt->close(); throw new DatabaseException(…); }
 *  - store_result(): it is the whole condition of an `if (!$stmt->store_result())`
 *    whose block throws.
 *
 * Anything else is unchecked: a chained `->get_result()->fetch_assoc()`, a
 * get_result() handed straight to a function or returned, an assignment the
 * next statement does not test, a test joined to another condition, a test
 * whose block returns instead of throwing. The api/v3 controllers have a
 * helper that does all of this — StatementHelpers::resultOf($stmt, $message).
 *
 * Legacy pages outside api/v3 that predate this test are listed in
 * KNOWN_UNCHECKED, which must only shrink: a file listed is reported when it
 * has no unchecked site left, and a file not listed fails the test with its
 * first unchecked site. A probe that does test for false, and then answers a
 * value of its own that callers can tell from a real answer instead of
 * throwing, is listed by function in GRACEFUL with why.
 */
final class UncheckedGetResultTest extends TestCase
{
    /**
     * Files that read a result unchecked and predate this test. None is under
     * api/v3, and none is code the API calls.
     *
     * @var list<string>
     */
    private const KNOWN_UNCHECKED = [
        '202-config/Messaging/MessagingService.class.php',
        '202-config/migrations/run_ltv_migration.php',
        '202-account/user-management.php',
        '202-account/account.php',
        '202-config/install.php',
    ];

    /**
     * file::function => why it tests get_result() for false and answers
     * without throwing. Only a site that does test for false can be listed:
     * an unchecked one is reported wherever it is.
     */
    private const GRACEFUL = [
        // AUTH::accountTimezone() was listed here: an unread zone answered
        // null, which set_timezone() read as "keep the session's zone", so a
        // failed read counted a page's days in a stale zone with nothing
        // said. It throws now (AUTH::resultOf()).
        'api/v3/Controllers/CapabilitiesController.php::loadClickServerKey' =>
            'Fails closed: a key that cannot be read is no key, so the shell capability is denied; /capabilities still answers.',
    ];

    /** @return array<string, list<int>> file => lines of unchecked get_result()/store_result() */
    public static function uncheckedSites(): array
    {
        $found = [];
        foreach (self::sites() as $path => $sites) {
            foreach ($sites as [$line, $function, $tested]) {
                if ($tested && isset(self::GRACEFUL[$path . '::' . $function])) {
                    continue;
                }
                $found[$path][] = $line;
            }
        }

        return $found;
    }

    /**
     * The lines of the unchecked get_result()/store_result() calls in a
     * source, by the rules in the class docblock.
     *
     * @return list<int>
     */
    public static function uncheckedIn(string $source): array
    {
        return array_map(static fn (array $site): int => $site[0], self::sitesIn($source));
    }

    public function testNoGetResultOrStoreResultIsUsedUnchecked(): void
    {
        $unexpected = array_diff_key(self::uncheckedSites(), array_flip(self::KNOWN_UNCHECKED));

        $this->assertSame([], $unexpected, sprintf(
            "These files use the result of get_result()/store_result() without checking it for false:\n%s\n"
            . 'False reads as "no rows" (or, chained, raises an \\Error naming nothing). Assign it, test it for '
            . 'false in the next statement and throw; in api/v3 use StatementHelpers::resultOf($stmt, $message).',
            implode("\n", array_map(
                static fn (string $f, array $lines): string => "  $f: line " . implode(', ', $lines),
                array_keys($unexpected),
                $unexpected
            ))
        ));
    }

    public function testTheKnownListHasNoStaleEntries(): void
    {
        $current = self::uncheckedSites();
        foreach (self::KNOWN_UNCHECKED as $file) {
            $this->assertFileExists(SourceScan::repoRoot() . '/' . $file);
            $this->assertArrayHasKey($file, $current, "$file no longer has an unchecked get_result()/store_result(): remove it from KNOWN_UNCHECKED");
            $this->assertStringStartsNotWith('api/v3/', $file, 'api/v3 is held to the rule without exceptions');
        }
    }

    public function testEveryGracefulProbeIsStillOne(): void
    {
        $sites = self::sites();
        foreach (self::GRACEFUL as $id => $why) {
            [$file, $function] = explode('::', $id);
            $matching = array_filter($sites[$file] ?? [], static fn (array $s): bool => $s[1] === $function && $s[2]);
            $this->assertNotSame([], $matching, "GRACEFUL lists $id ($why), which no longer tests a get_result() for false without throwing: remove it");
        }
    }

    /**
     * Every shape the docblock names, each executed through the scanner:
     * the checked ones are clean, the unchecked ones are reported.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function shapes(): iterable
    {
        $throw = '{ $stmt->close(); throw new \RuntimeException("x"); }';
        yield 'checked === false' => ['$r = $stmt->get_result(); if ($r === false) ' . $throw, true];
        yield 'checked false ===' => ['$r = $stmt->get_result(); if (false === $r) ' . $throw, true];
        yield 'checked !' => ['$r = $stmt->get_result(); if (!$r) ' . $throw, true];
        yield 'checked instanceof' => ['$r = $stmt->get_result(); if (!$r instanceof \mysqli_result) ' . $throw, true];
        yield 'checked instanceof, parenthesized' => ['$r = $stmt->get_result(); if (!($r instanceof mysqli_result)) ' . $throw, true];
        yield 'checked braceless throw' => ['$r = $stmt->get_result(); if ($r === false) throw new \RuntimeException("x");', true];
        yield 'checked through a property' => ['$r = $this->stmt->get_result(); if ($r === false) ' . $throw, true];
        yield 'store_result checked' => ['if (!$stmt->store_result()) ' . $throw, true];
        yield 'chained fetch' => ['$row = $stmt->get_result()->fetch_assoc();', false];
        yield 'chained nullsafe' => ['$row = $stmt->get_result()?->fetch_assoc();', false];
        yield 'returned' => ['return $stmt->get_result();', false];
        yield 'an argument' => ['foo($stmt->get_result());', false];
        yield 'assigned, then looped' => ['$r = $stmt->get_result(); while ($row = $r->fetch_assoc()) { $x[] = $row; }', false];
        yield 'assigned, tested later' => ['$r = $stmt->get_result(); $n = 1; if ($r === false) ' . $throw, false];
        yield 'tested, but returns' => ['$r = $stmt->get_result(); if ($r === false) { return []; }', false];
        yield 'tested, throw only in a nested block' => ['$r = $stmt->get_result(); if ($r === false) { if ($strict) { throw new \RuntimeException("x"); } }', false];
        yield 'tested truthy' => ['$r = $stmt->get_result(); if ($r) { $row = $r->fetch_assoc(); }', false];
        yield 'tested another variable' => ['$r = $stmt->get_result(); if ($q === false) ' . $throw, false];
        yield 'tested with an or' => ['$r = $stmt->get_result(); if ($r === false || $skip) ' . $throw, false];
        yield 'tested loosely' => ['$r = $stmt->get_result(); if ($r == false) ' . $throw, false];
        yield 'store_result discarded' => ['$stmt->store_result(); $n = $stmt->num_rows;', false];
        yield 'store_result assigned' => ['$ok = $stmt->store_result();', false];
        yield 'store_result in an and' => ['if (!$stmt->store_result() && $strict) ' . $throw, false];
        yield 'store_result tested, but returns' => ['if (!$stmt->store_result()) { return false; }', false];
    }

    /** @dataProvider shapes */
    public function testTheScannerReadsEachShape(string $code, bool $checked): void
    {
        $lines = self::uncheckedIn("<?php\nfunction f(\$stmt) {\n" . $code . "\n}\n");
        $this->assertSame($checked ? [] : [3], $lines, $code);
    }

    public function testASiteIsAttributedToTheFunctionItIsIn(): void
    {
        $source = "<?php\nclass C {\n    private function first(\$stmt) { \$r = \$stmt->get_result(); if (!\$r) { return 'unknown'; } }\n"
            . "    private function second(\$stmt) { \$r = \$stmt->get_result(); if (!\$r) { return 'unknown'; } }\n}\n";
        $this->assertSame([[3, 'first', true], [4, 'second', true]], self::sitesIn($source));
    }

    /** @return array<string, list<array{int, string, bool}>> file => [line, enclosing function, tested-for-false] */
    private static function sites(): array
    {
        $found = [];
        foreach (SourceScan::phpFiles() as $path => $source) {
            if (!str_contains($source, 'get_result') && !str_contains($source, 'store_result')) {
                continue;
            }
            $sites = self::sitesIn($source);
            if ($sites !== []) {
                $found[$path] = $sites;
            }
        }

        return $found;
    }

    /**
     * Each unchecked call: its line, the named function it is in ('' at file
     * scope), and whether it is tested for false (and only fails to refuse it).
     *
     * @return list<array{int, string, bool}>
     */
    private static function sitesIn(string $source): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $tokens[] = $t;
        }
        $sites = [];
        $count = count($tokens);
        $function = '';
        $functionEnd = -1;
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if ($i > $functionEnd) {
                $function = '';
            }
            if (is_array($t) && $t[0] === T_FUNCTION && is_array($tokens[$i + 1] ?? null) && ($tokens[$i + 2] ?? null) === '(') {
                // A named function or method; its body ends at the matching brace.
                $body = $i + 2;
                while ($body < $count && $tokens[$body] !== '{' && $tokens[$body] !== ';') {
                    $body++;
                }
                if (($tokens[$body] ?? null) === '{') {
                    $function = $tokens[$i + 1][1];
                    $functionEnd = self::matching($tokens, $body);
                }
                continue;
            }
            if (!is_array($t) || $t[0] !== T_STRING || !in_array(strtolower($t[1]), ['get_result', 'store_result'], true)) {
                continue;
            }
            $op = $tokens[$i - 1] ?? null;
            if (!is_array($op) || !in_array($op[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue; // a declaration, or a static/function of that name
            }
            if (($tokens[$i + 1] ?? null) !== '(' || ($tokens[$i + 2] ?? null) !== ')') {
                continue; // mysqli_stmt's take no arguments
            }
            $state = strtolower($t[1]) === 'get_result'
                ? self::getResultChecked($tokens, $i)
                : self::storeResultChecked($tokens, $i);
            if ($state !== 'refused') {
                $sites[] = [(int) $t[2], $function, $state === 'tested'];
            }
        }

        return $sites;
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return 'unchecked'|'tested'|'refused'
     */
    private static function getResultChecked(array $tokens, int $at): string
    {
        // `$var = <receiver> -> get_result ( ) ;`
        if (($tokens[$at + 3] ?? null) !== ';') {
            return 'unchecked';
        }
        $k = $at - 1;
        $depth = 0;
        // Walk back over the receiver to the `=`.
        while ($k > 0) {
            $k--;
            $t = $tokens[$k];
            if ($t === ')' || $t === ']') {
                $depth++;
                continue;
            }
            if ($t === '(' || $t === '[') {
                $depth--;
                continue;
            }
            if ($depth > 0) {
                continue;
            }
            if ($t === '=') {
                break;
            }
            if (is_array($t) && in_array($t[0], [T_VARIABLE, T_STRING, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_STATIC], true)) {
                continue;
            }
            return 'unchecked';
        }
        $var = $tokens[$k - 1] ?? null;
        $before = $tokens[$k - 2] ?? null;
        if (!is_array($var) || $var[0] !== T_VARIABLE || !in_array($before, [';', '{', '}'], true)) {
            return 'unchecked';
        }

        // The next statement: `if ( <failure test of $var> ) <throwing block>`.
        $if = $at + 4;
        if (!is_array($tokens[$if] ?? null) || $tokens[$if][0] !== T_IF || ($tokens[$if + 1] ?? null) !== '(') {
            return 'unchecked';
        }
        $close = self::matching($tokens, $if + 1);
        $cond = array_slice($tokens, $if + 2, $close - $if - 2);
        if (!self::isFailureTest($cond, $var[1])) {
            return 'unchecked';
        }

        return self::blockThrows($tokens, $close + 1) ? 'refused' : 'tested';
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return 'unchecked'|'tested'|'refused'
     */
    private static function storeResultChecked(array $tokens, int $at): string
    {
        // `if ( ! <receiver> -> store_result ( ) ) <throwing block>`
        if (($tokens[$at + 3] ?? null) !== ')') {
            return 'unchecked';
        }
        $k = $at - 1;
        while ($k > 0) {
            $k--;
            $t = $tokens[$k];
            if (is_array($t) && in_array($t[0], [T_VARIABLE, T_STRING, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            break;
        }
        if (($tokens[$k] ?? null) !== '!' || ($tokens[$k - 1] ?? null) !== '(' || !is_array($tokens[$k - 2] ?? null) || $tokens[$k - 2][0] !== T_IF) {
            return 'unchecked';
        }

        return self::blockThrows($tokens, $at + 4) ? 'refused' : 'tested';
    }

    /**
     * A condition that is exactly a failure test of $var: `$var === false`,
     * `false === $var`, `!$var`, `!$var instanceof X`, `!($var instanceof X)`.
     *
     * @param list<array{0:int,1:string,2:int}|string> $cond
     */
    private static function isFailureTest(array $cond, string $var): bool
    {
        $text = '';
        foreach ($cond as $t) {
            $text .= is_array($t) ? ($t[0] === T_VARIABLE ? ($t[1] === $var ? '$V' : '$other') : strtolower($t[1])) : $t;
            $text .= ' ';
        }
        $text = trim($text);

        return in_array($text, ['$V === false', 'false === $V', '! $V'], true)
            || preg_match('/^! \$V instanceof \\\\?mysqli_result$/', $text) === 1
            || preg_match('/^! \( \$V instanceof \\\\?mysqli_result \)$/', $text) === 1;
    }

    /**
     * Whether the statement at $from (an if's body) throws: a block with a
     * `throw` directly in it, or a braceless `throw`.
     *
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     */
    private static function blockThrows(array $tokens, int $from): bool
    {
        $t = $tokens[$from] ?? null;
        if (is_array($t) && $t[0] === T_THROW) {
            return true;
        }
        if ($t !== '{') {
            return false;
        }
        $end = self::matching($tokens, $from);
        $depth = 0;
        for ($k = $from + 1; $k < $end; $k++) {
            if ($tokens[$k] === '{' || (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($tokens[$k] === '}') {
                $depth--;
            } elseif ($depth === 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_THROW) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0:int,1:string,2:int}|string> $tokens */
    private static function matching(array $tokens, int $open): int
    {
        $pairs = ['(' => ')', '{' => '}', '[' => ']'];
        $depth = 0;
        $n = count($tokens);
        for ($k = $open; $k < $n; $k++) {
            $t = $tokens[$k];
            if (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (is_string($t) && isset($pairs[$t])) {
                $depth++;
            } elseif (is_string($t) && in_array($t, $pairs, true)) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return $n - 1;
    }
}
