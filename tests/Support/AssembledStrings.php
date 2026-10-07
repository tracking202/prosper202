<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A PHP source's strings as PHP assembles them, for the structural tests
 * that read SQL: literals, interpolated strings and heredocs/nowdocs (each
 * interpolation an unread part), and class constants resolved through the
 * file's namespace and imports (TableRegistry::CLICKS is '202_clicks'),
 * joined across `.`. A part the scan cannot read — a variable, a call, a
 * constant it cannot resolve — is UNREAD, so a statement whose table or
 * column list is built at runtime shows that it is, rather than reading as
 * some other statement (CLAUDE.md #20). The strings inside a call's
 * arguments are read as strings of their own, wherever the call sits. A
 * constant that holds a list gives each element as a string of its own.
 *
 * The reading RollupWritersAreMarkedTest does for table names, as a helper
 * other scans can share.
 */
final class AssembledStrings
{
    /** Stands in, inside an assembled string, for a part the scan cannot read. */
    public const UNREAD = "\x00";

    private const CASTS = [T_INT_CAST, T_STRING_CAST, T_DOUBLE_CAST, T_BOOL_CAST, T_ARRAY_CAST, T_OBJECT_CAST];

    /** Tokens that start an operand the scan cannot read. */
    private const UNREAD_OPERANDS = [
        T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_LNUMBER, T_DNUMBER, T_STATIC,
    ];

    private const NAMES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /**
     * @return list<array{line: int, text: string}>
     */
    public static function of(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $scope = self::scope($tokens);
        $out = [];
        self::scan($tokens, 0, count($tokens), $scope, $out);

        return $out;
    }

    /**
     * Every chain of string operands in [$from, $to).
     *
     * @param list<mixed> $tokens
     * @param array{ns: string, uses: array<string, string>, classes: array<int, string>} $scope
     * @param list<array{line: int, text: string}> $out
     */
    private static function scan(array $tokens, int $from, int $to, array $scope, array &$out): void
    {
        $i = $from;
        while ($i < $to) {
            $operand = self::operand($tokens, $i, $to, $scope, $out);
            if ($operand === null) {
                $i++;
                continue;
            }
            if ($operand[0] === null) {
                $i = max($i + 1, $operand[1]);
                continue;
            }
            [$text, $next, $line] = $operand;
            while ($next < $to && ($tokens[$next] ?? null) === '.') {
                $more = self::operand($tokens, $next + 1, $to, $scope, $out);
                if ($more === null) {
                    break;
                }
                $text .= $more[0] ?? self::UNREAD;
                $next = $more[1];
            }
            // Whatever follows the string — an operand the loop could not
            // join, a `.=`, or a later `$sql .= …` statement that continues
            // it — is unread too.
            $after = $tokens[$next] ?? null;
            $continued = $after === ';'
                && self::is($tokens[$next + 1] ?? null, [T_VARIABLE])
                && self::is($tokens[$next + 2] ?? null, [T_CONCAT_EQUAL]);
            if ($after === '.' || self::is($after, [T_CONCAT_EQUAL]) || $continued) {
                $text .= self::UNREAD;
            }
            $out[] = ['line' => $line, 'text' => $text];
            $i = max($i + 1, $next);
        }
    }

    /**
     * The string an operand at $i contributes: [text, next index, line];
     * [null, next index] for an operand the scan cannot read (whose call
     * arguments and index expressions are scanned on their own); null when
     * $i starts no operand it knows.
     *
     * @param list<mixed> $tokens
     * @param array{ns: string, uses: array<string, string>, classes: array<int, string>} $scope
     * @param list<array{line: int, text: string}> $out
     * @return array{0: string|null, 1: int, 2?: int}|null
     */
    private static function operand(array $tokens, int $i, int $to, array $scope, array &$out): ?array
    {
        if ($i >= $to) {
            return null;
        }
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $q = $t[1][0];
            $body = substr($t[1], 1, -1);
            $body = $q === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);

            return [$body, $i + 1, $t[2]];
        }
        if ($t === '"' || (is_array($t) && $t[0] === T_START_HEREDOC)) {
            $end = $t === '"' ? '"' : T_END_HEREDOC;
            $line = self::lineAt($tokens, $i);
            $text = '';
            $unread = false;
            for ($j = $i + 1; $j < $to; $j++) {
                $u = $tokens[$j];
                if ($u === $end || (is_array($u) && $u[0] === $end)) {
                    break;
                }
                if (is_array($u) && $u[0] === T_ENCAPSED_AND_WHITESPACE) {
                    $text .= $u[1];
                    $unread = false;
                } elseif (!$unread) {
                    $text .= self::UNREAD;
                    $unread = true;
                }
            }

            return [$text, $j + 1, $line];
        }
        $constant = self::is($t, [...self::NAMES, T_STATIC])
            && self::is($tokens[$i + 1] ?? null, [T_DOUBLE_COLON])
            && self::is($tokens[$i + 2] ?? null, [T_STRING])
            && ($tokens[$i + 3] ?? null) !== '(';
        if ($constant) {
            $value = self::constantValue($t[1], $tokens[$i + 2][1], $scope, $i);
            if (is_string($value)) {
                return [$value, $i + 3, $t[2]];
            }
            if (is_array($value)) {
                array_walk_recursive($value, static function ($v) use (&$out, $t): void {
                    if (is_string($v)) {
                        $out[] = ['line' => $t[2], 'text' => $v];
                    }
                });
            }

            return [null, $i + 3];
        }
        if (self::is($t, self::UNREAD_OPERANDS)) {
            $next = $i + 1;
            while ($next < $to) {
                $u = $tokens[$next];
                if ($u === '(' || $u === '[') {
                    $close = self::closing($tokens, $next, $to);
                    self::scan($tokens, $next + 1, $close, $scope, $out);
                    $next = $close + 1;
                } elseif (
                    self::is($u, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])
                    && self::is($tokens[$next + 1] ?? null, [T_STRING, T_VARIABLE, T_CLASS])
                ) {
                    $next += 2;
                } else {
                    break;
                }
            }

            return [null, $next];
        }
        if ($t === '(') {
            $close = self::closing($tokens, $i, $to);
            self::scan($tokens, $i + 1, $close, $scope, $out);

            return [null, $close + 1];
        }
        if ($t === '@' || $t === '-' || self::is($t, self::CASTS)) {
            // A cast or a prefix: the operand it applies to, unread.
            $inner = self::operand($tokens, $i + 1, $to, $scope, $out);

            return $inner === null ? null : [null, $inner[1]];
        }

        return null;
    }

    /**
     * The index of the bracket that closes the one at $open (or the end).
     *
     * @param list<mixed> $tokens
     */
    private static function closing(array $tokens, int $open, int $to): int
    {
        $want = ['(' => ')', '[' => ']'][$tokens[$open]] ?? null;
        if ($want === null) {
            return $open;
        }
        $depth = 0;
        for ($j = $open; $j < $to; $j++) {
            $u = $tokens[$j];
            if ($u === $tokens[$open]) {
                $depth++;
            } elseif ($u === $want) {
                $depth--;
                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return $to;
    }

    /**
     * Whether $token is one of the token ids in $ids.
     *
     * @param list<int> $ids
     */
    private static function is(mixed $token, array $ids): bool
    {
        return is_array($token) && in_array($token[0], $ids, true);
    }

    /** @param list<mixed> $tokens */
    private static function lineAt(array $tokens, int $i): int
    {
        for (; $i >= 0; $i--) {
            if (is_array($tokens[$i])) {
                return $tokens[$i][2];
            }
        }

        return 0;
    }

    /**
     * The file's namespace, its class imports, and the class each token
     * offset is inside (for self:: and static::).
     *
     * @param list<mixed> $tokens
     * @return array{ns: string, uses: array<string, string>, classes: array<int, string>}
     */
    private static function scope(array $tokens): array
    {
        $ns = '';
        $uses = [];
        $classes = [];
        $depth = 0;
        foreach ($tokens as $i => $t) {
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
            }
            if (!is_array($t)) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if ($t[0] === T_NAMESPACE && self::is($next, [T_STRING, T_NAME_QUALIFIED])) {
                $ns = $next[1];
            } elseif ($t[0] === T_USE && $depth === 0 && self::is($next, self::NAMES)) {
                // use A\B; use A\B as C; use A\B, D\E; (not use function / const, not a group)
                for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';'; $j++) {
                    $name = $tokens[$j];
                    if (!self::is($name, self::NAMES)) {
                        continue;
                    }
                    $full = ltrim($name[1], '\\');
                    $alias = substr($full, (int) strrpos('\\' . $full, '\\'));
                    if (self::is($tokens[$j + 1] ?? null, [T_AS]) && is_array($tokens[$j + 2] ?? null)) {
                        $alias = $tokens[$j + 2][1];
                        $j += 2;
                    }
                    $uses[strtolower($alias)] = $full;
                }
            } elseif (
                in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && self::is($next, [T_STRING])
                && !self::is($tokens[$i - 1] ?? null, [T_DOUBLE_COLON, T_NEW])
            ) {
                $classes[$i] = ($ns === '' ? '' : $ns . '\\') . $next[1];
            }
        }

        return ['ns' => $ns, 'uses' => $uses, 'classes' => $classes];
    }

    /**
     * The value of $class::$name as this file names it; null when the scan
     * cannot resolve it (it is then unread).
     *
     * @param array{ns: string, uses: array<string, string>, classes: array<int, string>} $scope
     */
    private static function constantValue(string $class, string $name, array $scope, int $at): mixed
    {
        $lower = strtolower($class);
        if ($lower === 'self' || $lower === 'static') {
            $fq = null;
            foreach ($scope['classes'] as $offset => $declared) {
                if ($offset < $at) {
                    $fq = $declared;
                }
            }
        } elseif ($lower === 'parent') {
            return null;
        } elseif ($class[0] === '\\') {
            $fq = substr($class, 1);
        } else {
            $first = strtolower(explode('\\', $class)[0]);
            $rest = substr($class, strlen($first));
            $fq = isset($scope['uses'][$first])
                ? $scope['uses'][$first] . $rest
                : ($scope['ns'] === '' ? '' : $scope['ns'] . '\\') . $class;
        }
        if ($fq === null) {
            return null;
        }
        try {
            if (!class_exists($fq) && !interface_exists($fq)) {
                return null;
            }
            $reflection = new \ReflectionClass($fq);

            return $reflection->hasConstant($name) ? $reflection->getConstant($name) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
