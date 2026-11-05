<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A try block that calls fail() and is caught by a supertype of PHPUnit's
 * AssertionFailedError (RuntimeException, Exception, Throwable or
 * PHPUnit\Framework\Exception) catches its own failure, so the test passes
 * whatever the code under test does (Y7YK). A catch of
 * AssertionFailedError (or ExpectationFailedException) before the broad one
 * rethrows it and is the accepted shape.
 */
final class FailInBroadCatch
{
    private const BROAD = ['RuntimeException', 'Exception', 'Throwable', 'PHPUnit\\Framework\\Exception'];
    private const GUARDS = ['AssertionFailedError', 'ExpectationFailedException'];

    /**
     * The lines of the source's try blocks of that shape, each with the broad type that catches.
     *
     * @return list<array{int, string}>
     */
    public static function sites(string $source): array
    {
        $t = array_values(array_filter(\PhpToken::tokenize($source), static fn (\PhpToken $x): bool => !$x->isIgnorable()));
        $n = count($t);
        $sites = [];
        for ($i = 0; $i < $n; $i++) {
            if ($t[$i]->id !== T_TRY || ($t[$i + 1]->text ?? '') !== '{') {
                continue;
            }
            [$end, $fails] = self::block($t, $i + 1);
            if (!$fails) {
                continue;
            }
            $m = $end + 1;
            $guarded = false;
            $broad = null;
            while ($m < $n && $t[$m]->id === T_CATCH) {
                $p = $m + 2;
                $types = [];
                for (; $p < $n && $t[$p]->text !== ')'; $p++) {
                    if ($t[$p]->id === T_STRING || $t[$p]->id === T_NAME_QUALIFIED || $t[$p]->id === T_NAME_FULLY_QUALIFIED) {
                        $types[] = ltrim($t[$p]->text, '\\');
                    }
                }
                foreach ($types as $type) {
                    $short = substr($type, (int) strrpos('\\' . $type, '\\'));
                    if ($broad === null && in_array($short, self::GUARDS, true)) {
                        $guarded = true;
                    }
                    if (!$guarded && in_array($type, self::BROAD, true)) {
                        $broad ??= $type;
                    }
                }
                for (; $p < $n && $t[$p]->text !== '{'; $p++) {
                }
                [$m] = self::block($t, $p);
                $m++;
            }
            if ($broad !== null) {
                $sites[] = [$t[$i]->line, $broad];
            }
        }

        return $sites;
    }

    /**
     * The index of the brace closing the block opened at $open, and whether fail() is called in it.
     *
     * @param list<\PhpToken> $t
     * @return array{int, bool}
     */
    private static function block(array $t, int $open): array
    {
        $depth = 0;
        $fails = false;
        $n = count($t);
        for ($k = $open; $k < $n; $k++) {
            if ($t[$k]->text === '{') {
                $depth++;
            } elseif ($t[$k]->text === '}' && --$depth === 0) {
                return [$k, $fails];
            }
            if ($t[$k]->text === 'fail' && in_array($t[$k - 1]->text, ['->', '::'], true) && ($t[$k + 1]->text ?? '') === '(') {
                $fails = true;
            }
        }

        return [$n - 1, $fails];
    }
}
