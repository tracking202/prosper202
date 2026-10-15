<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The functions a PHP source declares outside any class body, by the name PHP
 * files them under: the namespace in force, then the name, lowercased (PHP
 * names functions without regard to case). For DuplicateGlobalFunctionTest.
 *
 * Read from tokens, so a declaration is found in every place PHP lets one
 * stand: at file scope, in a block (`if (!function_exists(...)) { ... }`),
 * returning by reference, or inside another function's body, which declares
 * it globally once that body runs. Not declarations: methods (in a class,
 * interface, trait, enum or anonymous class body), closures, arrow
 * functions and `use function` imports.
 */
final class GlobalFunctionScan
{
    /**
     * @return list<array{name: string, line: int}>
     */
    public static function declarations(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn (array|string $t): bool => !is_array($t)
                || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)
        ));
        $count = count($tokens);

        $found = [];
        $namespace = '';
        $pendingNamespace = null;
        // What each open brace opened: 'class' (a class-like body), 'namespace', or 'other'.
        $braces = [];
        $parens = 0;
        $classParens = null;
        $inUse = false;

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $id = is_array($t) ? $t[0] : null;
            $prev = $tokens[$i - 1] ?? null;

            if ($id === T_HALT_COMPILER) {
                break;
            }
            if ($id === T_NAMESPACE) {
                $next = $tokens[$i + 1] ?? null;
                $name = is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true) ? $next[1] : '';
                if (($tokens[$i + ($name === '' ? 1 : 2)] ?? null) === '{') {
                    $pendingNamespace = $name;
                } else {
                    $namespace = $name;
                }
                continue;
            }
            $isClassLike = in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true);
            if ($isClassLike && !(is_array($prev) && $prev[0] === T_DOUBLE_COLON)) {
                $classParens = $parens;
                continue;
            }
            if ($t === '(') {
                $parens++;
                continue;
            }
            if ($t === ')') {
                $parens--;
                continue;
            }
            if ($t === '{' || in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                if ($t === '{' && $classParens === $parens) {
                    $braces[] = 'class';
                    $classParens = null;
                } elseif ($t === '{' && $pendingNamespace !== null) {
                    $braces[] = 'namespace';
                    $namespace = $pendingNamespace;
                    $pendingNamespace = null;
                } else {
                    $braces[] = 'other';
                }
                continue;
            }
            if ($t === '}') {
                if (array_pop($braces) === 'namespace') {
                    $namespace = '';
                }
                continue;
            }
            if ($id === T_USE) {
                // A closure's `use (` and a trait's `use` in a class body import no function.
                $inUse = ($tokens[$i + 1] ?? null) !== '(' && end($braces) !== 'class';
                continue;
            }
            if ($t === ';') {
                $inUse = false;
                continue;
            }
            if ($id !== T_FUNCTION || $inUse) {
                continue;
            }
            $j = $i + 1;
            // PHP 8.1 tokenizes `&` as one of two named tokens, not '&'.
            $amp = $tokens[$j] ?? null;
            $ampIds = [T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG];
            if ($amp === '&' || (is_array($amp) && in_array($amp[0], $ampIds, true))) {
                $j++;
            }
            $name = $tokens[$j] ?? null;
            if (!is_array($name) || $name[0] !== T_STRING || end($braces) === 'class') {
                continue;
            }
            $found[] = [
                'name' => strtolower(($namespace !== '' ? $namespace . '\\' : '') . $name[1]),
                'line' => $name[2],
            ];
        }

        return $found;
    }
}
