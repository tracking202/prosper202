<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Where a call runs relative to the click's held cache writes: inside a
 * p202WithHeldCacheWrites(...) call, or inside a closure that reaches one
 * (passed to it, assigned to a variable used inside it, or passed to a
 * callable whose own body holds). Anything else runs outside the hold.
 */
final class HeldLookupScan
{
    /** @var list<\PhpToken> */
    private array $code;
    /** @var list<array{int, int}> closure start => [bodyStart, bodyEnd] by index */
    private array $closures = [];
    /** @var list<array{int, int}> */
    private array $holds = [];

    public function __construct(string $source)
    {
        $this->code = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])
        ));
        foreach ($this->code as $i => $t) {
            if ($t->is(T_FUNCTION) && ($this->code[$i + 1] ?? null)?->text === '(') {
                $this->closures[$i] = $this->functionBody($i);
            } elseif ($t->is(T_FN)) {
                $this->closures[$i] = $this->arrowBody($i);
            } elseif ($t->is(T_STRING) && $t->text === 'p202WithHeldCacheWrites' && $this->opens($i + 1)) {
                $this->holds[] = [$i + 1, $this->matching($i + 1)];
            }
        }
    }

    /** @return list<array{int, string, bool}> each call: line, its argument as tokens joined, held */
    public function calls(string $method): array
    {
        $out = [];
        foreach ($this->code as $i => $t) {
            $prev = $this->code[$i - 1] ?? null;
            $member = $prev?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]);
            if ($t->is(T_STRING) && $t->text === $method && $this->opens($i + 1) && $member) {
                $close = $this->matching($i + 1);
                $arg = implode(' ', array_map(
                    static fn (\PhpToken $t): string => $t->text,
                    array_slice($this->code, $i + 2, $close - $i - 2)
                ));
                $out[] = [$t->line, $arg, $this->held($i, [])];
            }
        }

        return $out;
    }

    /** @param array<int, true> $seen */
    private function held(int $at, array $seen): bool
    {
        foreach ($this->holds as [$open, $close]) {
            if ($at > $open && $at < $close) {
                return true;
            }
        }
        $closure = $this->innermostClosure($at);
        if ($closure === null || isset($seen[$closure])) {
            return false;
        }
        $seen[$closure] = true;
        $p = $closure - 1;
        if ($this->code[$p]->is(T_STATIC)) {
            $p--;
        }
        $before = $this->code[$p];
        if ($before->text === '=' && ($this->code[$p - 1] ?? null)?->is(T_VARIABLE)) {
            // Assigned: held when the variable is used where held work runs.
            $name = $this->code[$p - 1]->text;
            foreach ($this->code as $i => $t) {
                if ($i !== $p - 1 && $t->is(T_VARIABLE) && $t->text === $name && $this->held($i, $seen)) {
                    return true;
                }
            }

            return false;
        }
        if ($before->text === '(' || $before->text === ',') {
            // An argument: held when the call it is passed to is held, or the
            // callee is a variable whose closure holds.
            $open = $this->openingParen($p);
            $callee = $this->code[$open - 1] ?? null;
            if ($callee !== null && $callee->is(T_VARIABLE) && $this->variableClosureHolds($callee->text)) {
                return true;
            }

            return $this->held($open - 1, $seen);
        }

        return false;
    }

    private function variableClosureHolds(string $name): bool
    {
        foreach ($this->closures as $start => [$from, $to]) {
            $p = $start - 1;
            if ($this->code[$p]->is(T_STATIC)) {
                $p--;
            }
            if ($this->code[$p]->text !== '=' || ($this->code[$p - 1]->text ?? '') !== $name) {
                continue;
            }
            foreach ($this->holds as [$open]) {
                if ($open > $from && $open < $to) {
                    return true;
                }
            }
        }

        return false;
    }

    private function innermostClosure(int $at): ?int
    {
        $best = null;
        foreach ($this->closures as $start => [$from, $to]) {
            if ($at > $from && $at < $to && ($best === null || $start > $best)) {
                $best = $start;
            }
        }

        return $best;
    }

    /** @return array{int, int} */
    private function functionBody(int $i): array
    {
        for ($j = $i; ($this->code[$j]->text ?? '{') !== '{'; $j++) {
        }

        return [$j, $this->matching($j)];
    }

    /** @return array{int, int} */
    private function arrowBody(int $i): array
    {
        for ($j = $i; $this->code[$j]->text !== '=>'; $j++) {
        }
        $depth = 0;
        for ($k = $j + 1, $n = count($this->code); $k < $n; $k++) {
            $text = $this->code[$k]->text;
            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if ($depth-- === 0) {
                    return [$j, $k];
                }
            } elseif ($depth === 0 && ($text === ',' || $text === ';')) {
                return [$j, $k];
            }
        }

        return [$j, $n];
    }

    private function opens(int $i): bool
    {
        return ($this->code[$i] ?? null)?->text === '(';
    }

    private function matching(int $open): int
    {
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];
        $depth = 0;
        for ($i = $open, $n = count($this->code); $i < $n; $i++) {
            $text = $this->code[$i]->text;
            if (isset($pairs[$text]) || $this->code[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if (--$depth === 0) {
                    return $i;
                }
            }
        }

        return $n;
    }

    private function openingParen(int $from): int
    {
        $depth = 0;
        for ($i = $from; $i >= 0; $i--) {
            $text = $this->code[$i]->text;
            if (in_array($text, [')', ']', '}'], true)) {
                $depth++;
            } elseif (
                in_array($text, ['(', '[', '{'], true)
                || $this->code[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])
            ) {
                if ($depth-- === 0) {
                    return $i;
                }
            }
        }

        return 0;
    }
}
