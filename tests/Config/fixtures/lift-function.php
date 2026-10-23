<?php

declare(strict_types=1);

/**
 * Lifts a global function's source out of a file that cannot be loaded on
 * its own (a bootstrap, or a file that declares half the app), so a test
 * process can evaluate the shipped body byte for byte beside stand-ins.
 */

/** The source of `function <name>(...) { ... }` in $path. */
function lift_function(string $path, string $name): string
{
    return lift_declaration($path, T_FUNCTION, $name);
}

/** The source of `class <name> { ... }` in $path. */
function lift_class(string $path, string $name): string
{
    return lift_declaration($path, T_CLASS, $name);
}

/** The source of the declaration that $keyword and then $name begin in $path. */
function lift_declaration(string $path, int $keyword, string $name): string
{
    $tokens = token_get_all((string) file_get_contents($path));
    $out = '';
    $capturing = false;
    $depth = 0;
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        $text = is_array($t) ? $t[1] : $t;
        if (!$capturing) {
            if (is_array($t) && $t[0] === $keyword) {
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING && $tokens[$j][1] === $name) {
                    $capturing = true;
                }
            }
            if (!$capturing) {
                continue;
            }
        }
        $out .= $text;
        if ($text === '{' || (is_array($t) && ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES))) {
            $depth++;
        } elseif ($text === '}') {
            $depth--;
            if ($depth === 0) {
                return $out;
            }
        }
    }
    fwrite(STDERR, "$name not found in $path\n");
    exit(3);
}
