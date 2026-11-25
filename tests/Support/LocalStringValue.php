<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The string a local variable holds where it is read, in the one shape this
 * can vouch for (CLAUDE.md #20, #22): inside a function or closure body, the
 * read is reached only through one statement `$name = <string chain>;`
 * standing directly in a block that encloses the read, and after that
 * statement nothing in the function does anything to `$name` but read it as
 * an operand of `.` or inside an interpolation. Everything else is refused
 * with the reason: a read at file scope, no such statement before the read,
 * a read outside the assignment's block, a `case`/`default` between them, a
 * value that is not a string chain ending the statement, any other use of
 * the name after the assignment (a write, `.=`, `++`, an index, a call
 * argument, a closure's `use`), any use before it but an assignment or a
 * read (a reference, `global`, `static`, a by-reference argument), a
 * reference taken by the function's own parameters or `use`, and a function
 * holding what can touch a variable without naming it (`extract()`,
 * `compact()`, `parse_str()`, `get_defined_vars()`, `$$name`, `${…}`,
 * `$GLOBALS`, `eval`, `include`/`require`, `goto`, an arrow function,
 * alternative block syntax).
 *
 * The value is the chain as SqlJoinScan reads it, its own holes kept as
 * holes: inlining it is what PHP's concatenation does, so a reader of the
 * result sees what it would see had the string been written at the read.
 *
 * @phpstan-type Chain array{line: int, text: string, holes: list<string>, start: int, end: int}
 */
final class LocalStringValue
{
    private const UNREADABLE_IDS = [
        T_EVAL => 'eval', T_INCLUDE => 'include', T_INCLUDE_ONCE => 'include_once', T_REQUIRE => 'require',
        T_REQUIRE_ONCE => 'require_once', T_GOTO => 'goto', T_FN => 'an arrow function',
        T_DOLLAR_OPEN_CURLY_BRACES => '${…}', T_ENDIF => 'endif', T_ENDWHILE => 'endwhile', T_ENDFOR => 'endfor',
        T_ENDFOREACH => 'endforeach', T_ENDSWITCH => 'endswitch', T_ENDDECLARE => 'enddeclare',
    ];

    private const UNREADABLE_CALLS = ['extract', 'compact', 'parse_str', 'mb_parse_str', 'get_defined_vars'];

    private const STATEMENT_STARTS = [';', '{', '}'];

    /**
     * Every function and closure body: the `function` token, its body's
     * `{` and matching `}`.
     *
     * @param list<\PhpToken> $tokens
     * @return list<array{fn: int, open: int, close: int}>
     */
    public static function bodies(array $tokens): array
    {
        $out = [];
        $n = count($tokens);
        for ($f = 0; $f < $n; $f++) {
            if ($tokens[$f]->id !== T_FUNCTION) {
                continue;
            }
            $i = $f + 1;
            while ($i < $n && $tokens[$i]->text !== '(' && !in_array($tokens[$i]->text, [';', '{', '='], true)) {
                $i++;
            }
            if ($i >= $n || $tokens[$i]->text !== '(') {
                continue; // `use function …;`
            }
            for ($i = self::match($tokens, $i) + 1; $i < $n; $i++) {
                $text = $tokens[$i]->text;
                if ($text === '(') {
                    $i = self::match($tokens, $i);
                } elseif ($text === '{' || $text === ';') {
                    break;
                }
            }
            if ($i < $n && $tokens[$i]->text === '{') {
                $out[] = ['fn' => $f, 'open' => $i, 'close' => self::match($tokens, $i)];
            }
        }

        return $out;
    }

    /**
     * The chain the variable at token $use holds there, or why not.
     *
     * @param list<\PhpToken> $tokens
     * @param list<array{fn: int, open: int, close: int}> $bodies
     * @param array<int, Chain> $byStart chains by their first token
     * @return array{chain: Chain}|array{refused: string}
     */
    public static function at(array $tokens, array $bodies, array $byStart, int $use): array
    {
        $name = $tokens[$use]->text;
        if ($tokens[$use]->id !== T_VARIABLE || $name === '$this' || $name === '$GLOBALS') {
            return ['refused' => 'not a local variable'];
        }
        $scope = null;
        foreach ($bodies as $body) {
            if ($body['open'] < $use && $use < $body['close'] && ($scope === null || $body['open'] > $scope['open'])) {
                $scope = $body;
            }
        }
        if ($scope === null) {
            return ['refused' => 'read outside a function'];
        }
        $nested = array_values(array_filter(
            $bodies,
            static fn (array $b): bool => $b['open'] > $scope['open'] && $b['close'] < $scope['close']
        ));
        for ($k = $scope['fn']; $k <= $scope['close']; $k++) {
            $tok = $tokens[$k];
            if (isset(self::UNREADABLE_IDS[$tok->id])) {
                return ['refused' => 'the function holds ' . self::UNREADABLE_IDS[$tok->id]];
            }
            if ($tok->text === '$' || $tok->text === '$GLOBALS') {
                return ['refused' => 'the function holds ' . ($tok->text === '$' ? '$$name' : '$GLOBALS')];
            }
            if ($tok->id === T_STRING && in_array(strtolower($tok->text), self::UNREADABLE_CALLS, true)) {
                return ['refused' => 'the function holds ' . strtolower($tok->text) . '()'];
            }
        }
        $kinds = [];
        for ($k = $scope['fn']; $k <= $scope['close']; $k++) {
            if ($tokens[$k]->id !== T_VARIABLE || $tokens[$k]->text !== $name) {
                continue;
            }
            foreach ($nested as $b) {
                if ($b['open'] < $k && $k < $b['close']) {
                    continue 2;
                }
            }
            $kinds[$k] = self::kind($tokens, $k, $k < $scope['open']);
        }
        if (($kinds[$use] ?? '') !== 'read') {
            return ['refused' => 'the read itself is not an operand of `.` or an interpolation'];
        }
        $assign = null;
        foreach ($kinds as $k => $kind) {
            if ($k < $use && $kind === 'assign') {
                $assign = $k;
            }
        }
        if ($assign === null) {
            return ['refused' => 'no `' . $name . ' = …;` statement before the read'];
        }
        foreach ($kinds as $k => $kind) {
            if ($k < $assign && !in_array($kind, ['assign', 'read', 'param'], true)) {
                return ['refused' => $name . ' is ' . $kind . ' at line ' . $tokens[$k]->line];
            }
            if ($k > $assign && $kind !== 'read') {
                return ['refused' => $name . ' is ' . ($kind === 'assign' ? 'assigned again' : $kind) . ' at line ' . $tokens[$k]->line];
            }
        }
        $depth = 0;
        for ($k = $assign; $k < $use; $k++) {
            $tok = $tokens[$k];
            if ($tok->text === '{' || $tok->id === T_CURLY_OPEN) {
                $depth++;
            } elseif ($tok->text === '}') {
                $depth--;
                if ($depth < 0) {
                    return ['refused' => 'the read at line ' . $tokens[$use]->line . ' is outside the block of the assignment at line ' . $tokens[$assign]->line];
                }
            } elseif ($tok->id === T_CASE || $tok->id === T_DEFAULT) {
                return ['refused' => 'a case label stands between the assignment and the read'];
            }
        }
        $eq = self::next($tokens, $assign + 1);
        $value = $eq === null ? null : self::next($tokens, $eq + 1);
        $chain = $value === null ? null : ($byStart[$value] ?? null);
        $end = $chain === null ? null : self::next($tokens, $chain['end'] + 1);
        if ($chain === null || $end === null || $tokens[$end]->text !== ';') {
            return ['refused' => 'the value assigned at line ' . $tokens[$assign]->line . ' is not a string chain ending the statement'];
        }

        return ['chain' => $chain];
    }

    /**
     * What the occurrence of a variable at $k does: 'assign' (a statement
     * `$x = …`, not by reference), 'read' (an operand of `.`, an
     * interpolation, a by-value foreach subject), 'param' (the function's
     * own by-value parameter or `use`), or a description of anything else.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function kind(array $tokens, int $k, bool $header): string
    {
        $p = self::prev($tokens, $k - 1);
        $n = self::next($tokens, $k + 1);
        $prev = $p === null ? '' : $tokens[$p]->text;
        $next = $n === null ? '' : $tokens[$n]->text;
        if ($header) {
            return $prev === '&' ? 'taken by reference' : 'param';
        }
        if ($prev === '&') {
            return 'taken by reference';
        }
        $raw = $tokens[$k - 1] ?? null;
        $after = $tokens[$k + 1] ?? null;
        $inString = $raw !== null && $after !== null
            && (in_array($raw->id, [T_ENCAPSED_AND_WHITESPACE, T_CURLY_OPEN, T_START_HEREDOC], true) || $raw->text === '"')
            && (in_array($after->id, [T_ENCAPSED_AND_WHITESPACE, T_END_HEREDOC], true) || $after->text === '"'
                || ($raw->id === T_CURLY_OPEN && $after->text === '}'));
        if ($inString) {
            return 'read';
        }
        if ($p !== null && in_array($prev, self::STATEMENT_STARTS, true) && $next === '=') {
            $value = self::next($tokens, (int) $n + 1);

            return $value !== null && $tokens[$value]->text === '&' ? 'assigned by reference' : 'assign';
        }
        if ($prev === '.' && in_array($next, ['.', ')', ';', ','], true)) {
            return 'read';
        }
        if ($next === '.' && (in_array($prev, ['(', ',', '=', '.'], true) || ($p !== null && $tokens[$p]->id === T_RETURN))) {
            return 'read';
        }
        $before = $p === null ? null : self::prev($tokens, $p - 1);
        if ($prev === '(' && $before !== null && $tokens[$before]->id === T_FOREACH && $n !== null && $tokens[$n]->id === T_AS) {
            return 'read';
        }

        return 'used otherwise (`' . $prev . ' ' . $tokens[$k]->text . ' ' . $next . '`)';
    }

    /** @param list<\PhpToken> $tokens */
    private static function match(array $tokens, int $open): int
    {
        $close = ['(' => ')', '{' => '}', '[' => ']'][$tokens[$open]->text];
        $depth = 0;
        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            $text = $tokens[$i]->text;
            if ($text === $tokens[$open]->text || ($close === '}' && in_array($tokens[$i]->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($text === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $n - 1;
    }

    /** @param list<\PhpToken> $tokens */
    private static function prev(array $tokens, int $i): ?int
    {
        for (; $i >= 0; $i--) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /** @param list<\PhpToken> $tokens */
    private static function next(array $tokens, int $i): ?int
    {
        for ($n = count($tokens); $i < $n; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }
}
