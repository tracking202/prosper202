<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * "A guards B" read from a PHP source (CLAUDE.md #22): whether every path
 * to a point in the file passes a call of a method of the same class first.
 *
 * A point is guarded when its innermost function or closure has, directly
 * in its body and in a statement before the one holding the point, a
 * guard statement:
 *
 *  - the call alone: `$this->guard(…);`, or assigned: `$x = $this->guard(…);`
 *    which counts only for a guard that refuses by throwing (the caller says);
 *  - an if that leaves when the guard says no: `if (!$this->guard(…))`,
 *    `if ($this->guard(…) === null)` or `=== false`, whose body is a
 *    `return`/`throw` or a block with one directly in it.
 *
 * A closure with no such statement is guarded where it is written; a
 * private method with none is guarded when it is named only as calls
 * (`$this->m(`, `self::m(`, `static::m(`) and every call is guarded. A
 * public or protected method is guarded only by its own body, since its
 * callers can be anywhere. A statement inside a braceless if, a loop or
 * another block does not guard what follows that block.
 *
 * Not read: whether the guard is given the id the write uses, what a
 * `goto` does, and PHP's alternative syntax (`if (…): … endif;`).
 */
final class CallsBefore
{
    /** @var array<string, array{tokens: list<\PhpToken>, units: list<array{kind: string, name: string, private: bool, fn: int, open: int, close: int}>}> */
    private static array $files = [];

    private function __construct()
    {
    }

    /**
     * Whether token $at of $source is reached only after a guard statement
     * calling $method. A guard that does not throw ($throws false) guards
     * only in an if that leaves.
     */
    public static function guarded(string $source, int $at, string $method, bool $throws): bool
    {
        return self::reach(self::file($source), $at, strtolower($method), $throws, []);
    }

    /**
     * The body of the method $name in $source, as [open, close] token
     * indexes of its braces; null when the file declares none.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function methodBody(string $source, string $name): ?array
    {
        foreach (self::file($source)['units'] as $unit) {
            if ($unit['kind'] === 'function' && strtolower($unit['name']) === strtolower($name)) {
                return [$unit['open'], $unit['close']];
            }
        }

        return null;
    }

    /** @return list<\PhpToken> */
    public static function tokens(string $source): array
    {
        return self::file($source)['tokens'];
    }

    /**
     * The methods of the same object a body calls (`$this->m(`, `self::m(`,
     * `static::m(`), lower case.
     *
     * @return list<string>
     */
    public static function callsIn(string $source, int $open, int $close): array
    {
        $tokens = self::file($source)['tokens'];
        $out = [];
        for ($i = $open + 1; $i < $close; $i++) {
            $call = self::callAt($tokens, $i);
            if ($call !== null) {
                $out[] = $call['name'];
            }
        }

        return array_values(array_unique($out));
    }

    /** Whether a body has a `throw` in it (at any depth). */
    public static function throwsIn(string $source, int $open, int $close): bool
    {
        $tokens = self::file($source)['tokens'];
        for ($i = $open + 1; $i < $close; $i++) {
            if ($tokens[$i]->id === T_THROW) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{tokens: list<\PhpToken>, units: list<array{kind: string, name: string, private: bool, fn: int, open: int, close: int}>} $file
     * @param array<string, true> $seen
     */
    private static function reach(array $file, int $at, string $method, bool $throws, array $seen): bool
    {
        $tokens = $file['tokens'];
        $unit = self::innermost($file['units'], $at);
        if ($unit === null) {
            return false;
        }
        foreach (self::statements($tokens, $unit['open'], $unit['close']) as [$start, $end]) {
            if ($start <= $at && $at <= $end) {
                break;
            }
            if (self::isGuard($tokens, $start, $end, $method, $throws)) {
                return true;
            }
        }
        if ($unit['kind'] === 'closure') {
            return self::reach($file, $unit['fn'], $method, $throws, $seen);
        }
        if ($unit['kind'] !== 'function' || !$unit['private'] || isset($seen[$unit['name']])) {
            return false;
        }
        $seen[$unit['name']] = true;
        $calls = self::references($tokens, $unit['name']);
        if ($calls === null || $calls === []) {
            return false;
        }
        foreach ($calls as $call) {
            if (!self::reach($file, $call, $method, $throws, $seen)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The token index of every call of the method $name in the file; null
     * when it is named any other way (a callable string, `[$this, 'm']`, a
     * first-class callable).
     *
     * @param list<\PhpToken> $tokens
     * @return list<int>|null
     */
    private static function references(array $tokens, string $name): ?array
    {
        $out = [];
        foreach ($tokens as $i => $tok) {
            if ($tok->id === T_CONSTANT_ENCAPSED_STRING && strtolower(trim($tok->text, '\'"')) === $name) {
                return null;
            }
            if ($tok->id !== T_STRING || strtolower($tok->text) !== $name) {
                continue;
            }
            $before = self::prev($tokens, $i - 1);
            if ($before !== null && $tokens[$before]->id === T_FUNCTION) {
                continue;
            }
            $start = $before === null ? null : self::prev($tokens, $before - 1);
            $call = $start === null ? null : self::callAt($tokens, $start);
            if ($call === null || $call['name'] !== $name) {
                return null;
            }
            $out[] = $i;
        }

        return $out;
    }

    /**
     * The call `$this->m(` / `self::m(` / `static::m(` starting at token $i,
     * with the index of its `(` and `)`; null when none starts there or it
     * is a first-class callable `m(...)`.
     *
     * @param list<\PhpToken> $tokens
     * @return array{name: string, open: int, close: int}|null
     */
    private static function callAt(array $tokens, int $i): ?array
    {
        $tok = $tokens[$i] ?? null;
        if ($tok === null) {
            return null;
        }
        $receiver = ($tok->id === T_VARIABLE && $tok->text === '$this') || ($tok->id === T_STATIC)
            || ($tok->id === T_STRING && strtolower($tok->text) === 'self');
        if (!$receiver) {
            return null;
        }
        $op = self::next($tokens, $i + 1);
        $wanted = $tok->id === T_VARIABLE ? [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR] : [T_DOUBLE_COLON];
        if ($op === null || !in_array($tokens[$op]->id, $wanted, true)) {
            return null;
        }
        $name = self::next($tokens, $op + 1);
        $open = $name === null ? null : self::next($tokens, $name + 1);
        if ($name === null || $tokens[$name]->id !== T_STRING || $open === null || $tokens[$open]->id !== ord('(')) {
            return null;
        }
        $close = self::closing($tokens, $open);
        $inside = self::next($tokens, $open + 1);
        if ($inside !== null && $tokens[$inside]->id === T_ELLIPSIS && self::next($tokens, $inside + 1) === $close) {
            return null;
        }

        return ['name' => strtolower($tokens[$name]->text), 'open' => $open, 'close' => $close];
    }

    /** @param list<\PhpToken> $tokens */
    private static function isGuard(array $tokens, int $start, int $end, string $method, bool $throws): bool
    {
        $first = $tokens[$start];
        $call = self::callAt($tokens, $start);
        if ($call === null && $first->id === T_VARIABLE) {
            $eq = self::next($tokens, $start + 1);
            $value = $eq !== null && $tokens[$eq]->id === ord('=') ? self::next($tokens, $eq + 1) : null;
            $call = $value === null ? null : self::callAt($tokens, $value);
        }
        if ($call !== null) {
            $after = self::next($tokens, $call['close'] + 1);

            return $throws && $call['name'] === $method && $after === $end && $tokens[$end]->id === ord(';');
        }
        if ($first->id !== T_IF) {
            return false;
        }
        $open = self::next($tokens, $start + 1);
        if ($open === null || $tokens[$open]->id !== ord('(')) {
            return false;
        }
        $close = self::closing($tokens, $open);
        if (!self::refusesWhenNo($tokens, $open, $close, $method)) {
            return false;
        }
        $body = self::next($tokens, $close + 1);
        if ($body === null) {
            return false;
        }
        if ($tokens[$body]->id !== ord('{')) {
            $bodyEnd = self::statementEnd($tokens, $body, $end + 1);

            return self::leaves($tokens[$body]) && $bodyEnd === $end;
        }
        $bodyClose = self::closing($tokens, $body);
        if ($bodyClose !== $end) {
            return false; // an else or elseif follows
        }
        foreach (self::statements($tokens, $body, $bodyClose) as [$s]) {
            if (self::leaves($tokens[$s])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The condition between ( and ) is the guard's no: `!$this->m(…)`, or
     * `$this->m(…) === null|false` either way round.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function refusesWhenNo(array $tokens, int $open, int $close, string $method): bool
    {
        $sig = [];
        for ($i = $open + 1; $i < $close; $i++) {
            if (!$tokens[$i]->isIgnorable()) {
                $sig[] = $i;
            }
        }
        if ($sig === []) {
            return false;
        }
        $whole = static function (int $from, int $to) use ($tokens, $sig, $method): bool {
            $call = self::callAt($tokens, $sig[$from]);

            return $call !== null && $call['name'] === $method && $call['close'] === $sig[$to];
        };
        $last = count($sig) - 1;
        if ($tokens[$sig[0]]->id === ord('!') && $whole(1, $last)) {
            return true;
        }
        $none = static fn (int $k): bool => in_array(strtolower($tokens[$sig[$k]]->text), ['null', 'false'], true);
        $identical = static fn (int $k): bool => $tokens[$sig[$k]]->id === T_IS_IDENTICAL;

        return ($last >= 2 && $none($last) && $identical($last - 1) && $whole(0, $last - 2))
            || ($last >= 2 && $none(0) && $identical(1) && $whole(2, $last));
    }

    private static function leaves(\PhpToken $tok): bool
    {
        return in_array($tok->id, [T_RETURN, T_THROW, T_EXIT], true);
    }

    /**
     * The statements directly in the block whose braces are at $open and
     * $close, as [first token, last token].
     *
     * @param list<\PhpToken> $tokens
     * @return list<array{0: int, 1: int}>
     */
    public static function statements(array $tokens, int $open, int $close): array
    {
        $out = [];
        $i = self::next($tokens, $open + 1);
        while ($i !== null && $i < $close) {
            $end = self::statementEnd($tokens, $i, $close);
            $out[] = [$i, $end];
            $i = self::next($tokens, $end + 1);
        }

        return $out;
    }

    /** @param list<\PhpToken> $tokens */
    private static function statementEnd(array $tokens, int $start, int $limit): int
    {
        $block = in_array($tokens[$start]->id, [T_IF, T_WHILE, T_FOR, T_FOREACH, T_SWITCH, T_TRY, T_DECLARE], true)
            || $tokens[$start]->id === ord('{');
        $depth = 0;
        for ($i = $start; $i < $limit; $i++) {
            $t = $tokens[$i];
            if (in_array(self::punct($t), ['(', '[', '{'], true) || in_array($t->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (in_array(self::punct($t), [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0 && $t->id === ord('}') && $block) {
                    $next = self::next($tokens, $i + 1);
                    if ($next === null || !in_array($tokens[$next]->id, [T_ELSE, T_ELSEIF, T_CATCH, T_FINALLY], true)) {
                        return $i;
                    }
                }
            } elseif ($depth === 0 && ($t->id === ord(';') || $t->id === T_CLOSE_TAG)) {
                $next = self::next($tokens, $i + 1);
                if ($block && $next !== null && in_array($tokens[$next]->id, [T_ELSE, T_ELSEIF], true)) {
                    continue;
                }

                return $i;
            }
        }

        return $limit - 1;
    }

    /**
     * @param list<array{kind: string, name: string, private: bool, fn: int, open: int, close: int}> $units
     * @return array{kind: string, name: string, private: bool, fn: int, open: int, close: int}|null
     */
    private static function innermost(array $units, int $at): ?array
    {
        $best = null;
        foreach ($units as $unit) {
            if ($unit['open'] < $at && $at < $unit['close'] && ($best === null || $unit['open'] > $best['open'])) {
                $best = $unit;
            }
        }

        return $best;
    }

    /**
     * @return array{tokens: list<\PhpToken>, units: list<array{kind: string, name: string, private: bool, fn: int, open: int, close: int}>}
     */
    private static function file(string $source): array
    {
        $key = md5($source);
        if (isset(self::$files[$key])) {
            return self::$files[$key];
        }
        $tokens = \PhpToken::tokenize($source);
        $units = [];
        foreach ($tokens as $i => $tok) {
            if ($tok->id !== T_FUNCTION && $tok->id !== T_FN) {
                continue;
            }
            $j = self::next($tokens, $i + 1);
            if ($j !== null && $tokens[$j]->text === '&') {
                $j = self::next($tokens, $j + 1);
            }
            $named = $tok->id === T_FUNCTION && $j !== null && $tokens[$j]->id === T_STRING;
            $paren = $named ? self::next($tokens, $j + 1) : $j;
            if ($paren === null || $tokens[$paren]->id !== ord('(')) {
                continue;
            }
            $k = self::closing($tokens, $paren) + 1;
            if ($tok->id === T_FN) {
                // fn (…) => expr: the expression, to the end of its statement or argument.
                while (isset($tokens[$k]) && $tokens[$k]->id !== T_DOUBLE_ARROW) {
                    $k++;
                }
                $end = $k;
                $depth = 0;
                for ($e = $k + 1; isset($tokens[$e]); $e++) {
                    $text = self::punct($tokens[$e]);
                    if (in_array($text, ['(', '[', '{'], true) || in_array($tokens[$e]->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                        $depth++;
                    } elseif (in_array($text, [')', ']', '}'], true)) {
                        if ($depth === 0) {
                            break;
                        }
                        $depth--;
                    } elseif ($depth === 0 && in_array($text, [';', ','], true)) {
                        break;
                    }
                    $end = $e;
                }
                $units[] = ['kind' => 'closure', 'name' => '', 'private' => false, 'fn' => $i, 'open' => $k, 'close' => $end + 1];
                continue;
            }
            while (isset($tokens[$k]) && $tokens[$k]->id !== ord('{') && $tokens[$k]->id !== ord(';')) {
                $k++;
            }
            if (!isset($tokens[$k]) || $tokens[$k]->id !== ord('{')) {
                continue; // abstract or interface method
            }
            $private = false;
            for ($b = self::prev($tokens, $i - 1); $b !== null; $b = self::prev($tokens, $b - 1)) {
                $id = $tokens[$b]->id;
                if ($id === T_PRIVATE) {
                    $private = true;
                } elseif (!in_array($id, [T_STATIC, T_FINAL, T_ABSTRACT, T_PUBLIC, T_PROTECTED, T_READONLY], true)) {
                    break;
                }
            }
            $units[] = [
                'kind' => $named ? 'function' : 'closure',
                'name' => $named ? strtolower($tokens[$j]->text) : '',
                'private' => $private,
                'fn' => $i,
                'open' => $k,
                'close' => self::closing($tokens, $k),
            ];
        }

        return self::$files[$key] = ['tokens' => $tokens, 'units' => $units];
    }

    /** @param list<\PhpToken> $tokens */
    private static function closing(array $tokens, int $open): int
    {
        $depth = 0;
        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            $t = $tokens[$i];
            if (in_array(self::punct($t), ['(', '[', '{'], true) || in_array($t->id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (in_array(self::punct($t), [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return count($tokens) - 1;
    }

    /** A one-character token's character; '' for any other (a string's text is never punctuation). */
    private static function punct(\PhpToken $tok): string
    {
        return $tok->id < 256 ? chr($tok->id) : '';
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
}
