<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Shared machinery for the structural tests that walk the repository's PHP
 * sources and assert an invariant holds everywhere (UncheckedExecuteTest,
 * UncheckedTransactionBoundaryTest, DoubleStatementCloseTest, ...).
 *
 * Two things live here so they exist exactly once:
 *
 *  - the tree walk, memoized per process, with the exclusion list in one
 *    place and every file read checked -- `(string) file_get_contents()`
 *    turns an unreadable file into an empty one that scans clean;
 *  - token-based statement analysis. The scanners used to be line regexes,
 *    and every one of them missed the same shapes: a call with arguments, a
 *    trailing comment, a CRLF line ending, a chained receiver like
 *    `$this->conn()->commit()`, two statements on one line. Working on
 *    token_get_all() output instead of lines makes those shapes ordinary.
 *
 * Nothing here does type inference. That is deliberate: PHPStan rules cover
 * the receivers whose type is known, and these scanners are the complement for
 * the untyped legacy code where inference has nothing to work with.
 */
final class SourceScan
{
    /**
     * Directories never descended into. vendor/node_modules/.git are not ours;
     * the rest contain no PHP at all and are only skipped for speed --
     * testPrunedDirectoriesContainNoPhp() in SourceScanTest keeps that true.
     */
    public const PRUNED = ['vendor', 'node_modules', '.git', '202-css', '202-img', 'documentation', 'docs', 'go-cli'];

    /** @var array<string, array<string, string>> keyed by includeTests flag */
    private static array $cache = [];

    public static function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Every PHP source under the repository, repo-relative path => contents.
     * tests/ is excluded by default because tests exercise failure shapes on
     * purpose.
     *
     * @return array<string, string>
     */
    public static function phpFiles(bool $includeTests = false): array
    {
        $key = $includeTests ? 'with-tests' : 'no-tests';
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $root = self::repoRoot();
        $pruned = self::PRUNED;
        if (!$includeTests) {
            $pruned[] = 'tests';
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn(\SplFileInfo $file): bool => !in_array($file->getFilename(), $pruned, true)
            )
        );

        $files = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $source = file_get_contents($path);
            if ($source === false) {
                // Never let an unreadable file scan as an empty one.
                throw new RuntimeException("Could not read $path");
            }
            $files[substr($path, strlen($root) + 1)] = $source;
        }
        ksort($files);

        return self::$cache[$key] = $files;
    }

    /**
     * preg_match_all() that refuses to report a failed scan as "no matches".
     * A pattern that exhausts the backtrack limit on a large file returns
     * false, which a scanner reading `$count > 0` treats as clean.
     *
     * @param array<mixed>|null $matches receives preg_match_all()'s matches
     */
    public static function countMatches(string $pattern, string $source, string $file, ?array &$matches = null): int
    {
        $hits = preg_match_all($pattern, $source, $matches);
        if ($hits === false) {
            throw new RuntimeException("Scanning $file failed: " . preg_last_error_msg());
        }

        return $hits;
    }

    /**
     * Lines on which a call to one of $methods (as `->name(...)` or
     * `::name(...)`) or one of $functions (as a bare `name(...)`) forms a
     * whole statement whose result is discarded: nothing between the previous
     * statement boundary and the call but the receiver expression, and a `;`
     * straight after the closing parenthesis.
     *
     * `$x = $db->commit();`, `if (!$db->commit())`, `return $db->commit();`
     * and `$ok && $db->commit()` are all "used" and not reported. The receiver
     * may be anything: `$db`, `$this->db`, `self::$db`, `$conns['w']`,
     * `$this->conn()->getWrite()`.
     *
     * $zeroArgsOnly restricts to calls with an empty argument list. The
     * execute() scanner needs it: the checked wrappers are also called
     * `execute` (Connection::execute($stmt), StatementHelpers::execute($stmt,
     * $message)) and are told apart from a raw `$stmt->execute()` only by
     * taking arguments.
     *
     * @param list<string> $methods
     * @param list<string> $functions
     * @return list<int> 1-based line numbers
     */
    public static function uncheckedCallStatements(string $source, array $methods, array $functions = [], bool $zeroArgsOnly = false): array
    {
        $tokens = self::significantTokens($source);
        $count = count($tokens);
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_STRING) {
                continue;
            }
            $name = $tok[1];
            $prev = $i > 0 ? $tokens[$i - 1] : null;
            $isMethod = in_array($name, $methods, true)
                && is_array($prev)
                && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
            $isFunction = in_array($name, $functions, true)
                && !(is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true))
                && $prev !== '\\' && !(is_array($prev) && $prev[0] === T_NS_SEPARATOR);
            if (!$isMethod && !$isFunction) {
                continue;
            }
            // Must be a call: next token is "(".
            if (($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }
            $close = self::matchingParen($tokens, $i + 1);
            if ($close === null || ($tokens[$close + 1] ?? null) !== ';') {
                continue;
            }
            if ($zeroArgsOnly && $close !== $i + 2) {
                continue;
            }
            // Walk back to the statement boundary and make sure the prefix is
            // only a receiver expression.
            $start = $i;
            while ($start > 0 && !self::isStatementBoundary($tokens[$start - 1])) {
                $start--;
            }
            if (!self::isPureReceiver(array_slice($tokens, $start, $i - $start))) {
                continue;
            }
            $lines[] = $tok[2];
        }

        return $lines;
    }

    /**
     * Lines on which `$var->close()` is called after `$var` was handed to one
     * of $closingHelpers (methods that close the statement themselves) in the
     * same function body, with no reassignment of `$var` in between.
     *
     * @param list<string> $closingHelpers
     * @return list<int> 1-based line numbers of the redundant close()
     */
    public static function closesAfterClosingHelper(string $source, array $closingHelpers): array
    {
        $tokens = self::significantTokens($source);
        $count = count($tokens);
        $functionEnds = self::functionBodyEnds($tokens);
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_STRING || !in_array($tok[1], $closingHelpers, true)) {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            if (!is_array($prev) || !in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }
            // helper( $var ...
            $arg = $tokens[$i + 2] ?? null;
            if (($tokens[$i + 1] ?? null) !== '(' || !is_array($arg) || $arg[0] !== T_VARIABLE) {
                continue;
            }
            $var = $arg[1];
            $end = $functionEnds[$i] ?? $count - 1;

            for ($j = $i + 3; $j <= $end; $j++) {
                $t = $tokens[$j];
                if (!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== $var) {
                    continue;
                }
                $next = $tokens[$j + 1] ?? null;
                if ($next === '=') {
                    break; // reassigned: a fresh statement from here on
                }
                if (is_array($next) && in_array($next[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    $m = $tokens[$j + 2] ?? null;
                    if (is_array($m) && $m[0] === T_STRING && $m[1] === 'close' && ($tokens[$j + 3] ?? null) === '(') {
                        $lines[] = $m[2];
                    }
                }
            }
        }

        return array_values(array_unique($lines));
    }

    // -------------------------------------------------------------------

    /**
     * token_get_all() minus whitespace and comments, so adjacency checks mean
     * "next meaningful token". Single-char tokens stay strings.
     *
     * @return list<string|array{0:int,1:string,2:int}>
     */
    private static function significantTokens(string $source): array
    {
        $out = [];
        foreach (token_get_all($source) as $t) {
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    /** @param list<string|array{0:int,1:string,2:int}> $tokens */
    private static function matchingParen(array $tokens, int $open): ?int
    {
        $depth = 0;
        $n = count($tokens);
        for ($i = $open; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($t === '(') {
                $depth++;
            } elseif ($t === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @param string|array{0:int,1:string,2:int} $t */
    private static function isStatementBoundary(string|array $t): bool
    {
        if (is_string($t)) {
            return in_array($t, [';', '{', '}', ':'], true);
        }

        return in_array($t[0], [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO], true);
    }

    /**
     * True when the tokens form nothing but a receiver expression: variables,
     * property/static access, identifiers, parenthesised calls, array offsets
     * and literals. Any operator or keyword means the call's value is used.
     *
     * @param list<string|array{0:int,1:string,2:int}> $tokens
     */
    private static function isPureReceiver(array $tokens): bool
    {
        // `if ($x) $db->commit();` -- a brace-less control clause is not part
        // of the receiver, and the value is just as discarded. Strip it.
        while ($tokens !== [] && is_array($tokens[0])
            && in_array($tokens[0][0], [T_IF, T_ELSEIF, T_WHILE, T_FOR, T_FOREACH, T_ELSE], true)) {
            $head = array_shift($tokens);
            if ($head[0] !== T_ELSE) {
                $close = self::matchingParen($tokens, 0);
                if ($close === null) {
                    return false;
                }
                $tokens = array_slice($tokens, $close + 1);
            }
        }

        foreach ($tokens as $t) {
            if (is_string($t)) {
                if (!in_array($t, ['(', ')', '[', ']', ',', '\\'], true)) {
                    return false;
                }
                continue;
            }
            if (!in_array($t[0], [
                T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR,
                T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
                T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_STATIC,
            ], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * For every token index inside a function body, the index of the `}`
     * that closes that body (innermost function wins).
     *
     * @param list<string|array{0:int,1:string,2:int}> $tokens
     * @return array<int, int>
     */
    private static function functionBodyEnds(array $tokens): array
    {
        $n = count($tokens);
        $ends = [];
        // Pass 1: find each function body's "{" and its matching "}".
        $bodies = [];
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_FUNCTION) {
                continue;
            }
            // Skip to the body "{" (past the parameter list and return type);
            // an abstract/interface method ends in ";" and has no body.
            $j = $i + 1;
            while ($j < $n && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
                if ($tokens[$j] === '(') {
                    $j = self::matchingParen($tokens, $j) ?? $n;
                }
                $j++;
            }
            if ($j >= $n || $tokens[$j] !== '{') {
                continue;
            }
            $depth = 0;
            for ($k = $j; $k < $n; $k++) {
                if ($tokens[$k] === '{') {
                    $depth++;
                } elseif ($tokens[$k] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        $bodies[] = [$j, $k];
                        break;
                    }
                }
            }
        }
        // Pass 2: innermost body wins -- later (nested) bodies overwrite.
        usort($bodies, static fn(array $a, array $b): int => ($b[1] - $b[0]) <=> ($a[1] - $a[0]));
        foreach ($bodies as [$open, $close]) {
            for ($i = $open; $i <= $close; $i++) {
                $ends[$i] = $close;
            }
        }

        return $ends;
    }
}
