<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Token-level reading of calls for the structural tests that ask "what does
 * this call receive?": find the calls to a function or method by name, split
 * each one's arguments at their top-level commas, and read an inline array
 * argument's entries. Whitespace and comments are dropped, so an argument's
 * text is its tokens joined (`p202StoredVisitorIp()`, `(string)$x`).
 *
 * A name is a call only when `(` follows it and `function`, `new` or `const`
 * does not precede it; a method call (`->`, `?->`, `::`) is reported as one,
 * with the operator, so a caller can tell `$repo->find(` from `find(`.
 */
final class CallArgs
{
    /**
     * Every call to one of $names (compared case-insensitively, a leading
     * backslash ignored).
     *
     * @param list<string> $names
     * @return list<array{line: int, name: string, operator: string, args: list<list<mixed>>}>
     */
    public static function calls(string $source, array $names): array
    {
        $tokens = self::significantTokens($source);
        $wanted = array_map('strtolower', $names);
        $calls = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if (($tokens[$i + 1] ?? null) !== '(' || !in_array(strtolower(ltrim($t[1], '\\')), $wanted, true)) {
                continue;
            }
            $prev = $tokens[$i - 1] ?? null;
            if (is_array($prev) && in_array($prev[0], [T_FUNCTION, T_NEW, T_CONST], true)) {
                continue;
            }
            $memberOperators = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];
            $operator = is_array($prev) && in_array($prev[0], $memberOperators, true)
                ? $prev[1]
                : '';
            $close = self::matching($tokens, $i + 1);
            $calls[] = [
                'line' => $t[2],
                'name' => strtolower(ltrim($t[1], '\\')),
                'operator' => $operator,
                'args' => self::split(array_slice($tokens, $i + 2, $close - $i - 2)),
            ];
        }

        return $calls;
    }

    /**
     * The entries of an inline array argument (`[...]` or `array(...)`),
     * key text => value tokens, or null when the argument is not one.
     * A key that is not a single string literal is reported as its text.
     *
     * @param list<string|array{0:int,1:string,2:int}> $arg
     * @return array<string, list<string|array{0:int,1:string,2:int}>>|null
     */
    public static function arrayEntries(array $arg): ?array
    {
        if ($arg === []) {
            return null;
        }
        if ($arg[0] === '[' && self::matching($arg, 0) === count($arg) - 1) {
            $inner = array_slice($arg, 1, -1);
        } elseif (
            is_array($arg[0]) && $arg[0][0] === T_ARRAY && ($arg[1] ?? null) === '('
            && self::matching($arg, 1) === count($arg) - 1
        ) {
            $inner = array_slice($arg, 2, -1);
        } else {
            return null;
        }
        $entries = [];
        foreach (self::split($inner) as $element) {
            $depth = 0;
            foreach ($element as $j => $tok) {
                $depth += self::opens($tok) ? 1 : (in_array($tok, [')', ']', '}'], true) ? -1 : 0);
                if ($depth === 0 && is_array($tok) && $tok[0] === T_DOUBLE_ARROW) {
                    $key = array_slice($element, 0, $j);
                    $text = count($key) === 1 && is_array($key[0]) && $key[0][0] === T_CONSTANT_ENCAPSED_STRING
                        ? substr($key[0][1], 1, -1)
                        : self::text($key);
                    $entries[$text] = array_slice($element, $j + 1);
                    break;
                }
            }
        }

        return $entries;
    }

    /**
     * The entries of the array literal that decides $arg's keys: the whole
     * argument, or the left operand of a `+` (PHP's array union keeps the
     * left operand's keys, so `[...] + $more` is decided by the literal and
     * `$more + [...]` is not). Null when no literal decides.
     *
     * @param list<string|array{0:int,1:string,2:int}> $arg
     * @return array<string, list<string|array{0:int,1:string,2:int}>>|null
     */
    public static function leadingArray(array $arg): ?array
    {
        $whole = self::arrayEntries($arg);
        if ($whole !== null || $arg === []) {
            return $whole;
        }
        $isArray = is_array($arg[0]) && $arg[0][0] === T_ARRAY && ($arg[1] ?? null) === '(';
        if ($arg[0] !== '[' && !$isArray) {
            return null;
        }
        $close = self::matching($arg, $isArray ? 1 : 0);
        if (($arg[$close + 1] ?? null) !== '+') {
            return null;
        }

        return self::arrayEntries(array_slice($arg, 0, $close + 1));
    }

    /** @param list<string|array{0:int,1:string,2:int}> $tokens */
    public static function text(array $tokens): string
    {
        return implode('', array_map(static fn ($t): string => is_array($t) ? $t[1] : $t, $tokens));
    }

    /** @return list<string|array{0:int,1:string,2:int}> */
    private static function significantTokens(string $source): array
    {
        $out = [];
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG];
        foreach (token_get_all($source) as $t) {
            if (!(is_array($t) && in_array($t[0], $skip, true))) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /**
     * @param list<string|array{0:int,1:string,2:int}> $tokens
     * @return list<list<string|array{0:int,1:string,2:int}>>
     */
    private static function split(array $tokens): array
    {
        $parts = [[]];
        $depth = 0;
        foreach ($tokens as $tok) {
            if (self::opens($tok)) {
                $depth++;
            } elseif (in_array($tok, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($tok === ',' && $depth === 0) {
                $parts[] = [];
                continue;
            }
            $parts[count($parts) - 1][] = $tok;
        }

        return $parts === [[]] ? [] : $parts;
    }

    /** @param list<string|array{0:int,1:string,2:int}> $tokens */
    private static function matching(array $tokens, int $open): int
    {
        $depth = 0;
        $n = count($tokens);
        for ($i = $open; $i < $n; $i++) {
            if (self::opens($tokens[$i])) {
                $depth++;
            } elseif (in_array($tokens[$i], [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        throw new RuntimeException('unbalanced brackets from token ' . $open);
    }

    /** An opening bracket, including `{$` and `${` inside a string. */
    private static function opens(string|array $tok): bool
    {
        return in_array($tok, ['(', '[', '{'], true)
            || (is_array($tok) && in_array($tok[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
    }
}
