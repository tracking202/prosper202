<?php

declare(strict_types=1);

namespace Tests\Support;

use Prosper202\Lifecycle\State;

/**
 * What every lifecycle enum (Prosper202\Lifecycle\State) must hold, read from
 * its own transition table. Returns what is wrong, as sentences; none is a
 * pass.
 */
final class LifecycleContract
{
    /**
     * @param class-string<State> $lifecycle
     * @return list<string>
     */
    public static function violations(string $lifecycle): array
    {
        $wrong = [];
        $cases = $lifecycle::cases();
        if ($cases === []) {
            return [$lifecycle . ' has no states.'];
        }
        $initial = $lifecycle::initial();
        if ($initial === []) {
            $wrong[] = $lifecycle . ' names no initial state, so no record can exist.';
        }
        foreach ($initial as $state) {
            if (!$state instanceof $lifecycle) {
                $wrong[] = $lifecycle . '::initial() holds a value of another type.';
            }
        }
        $reached = [];
        $queue = $initial;
        while ($queue !== []) {
            $state = array_shift($queue);
            if (!$state instanceof $lifecycle || isset($reached[$state->name])) {
                continue;
            }
            $reached[$state->name] = true;
            $next = $state->next();
            if (count($next) !== count(array_unique(array_map(static fn (State $s): string => $s->name, $next)))) {
                $wrong[] = $lifecycle . '::' . $state->name . ' lists a move twice.';
            }
            foreach ($next as $to) {
                if (!$to instanceof $lifecycle) {
                    $wrong[] = $lifecycle . '::' . $state->name . ' moves to a state of another lifecycle.';
                    continue;
                }
                if ($to === $state) {
                    $wrong[] = $lifecycle . '::' . $state->name . ' moves to itself: a write that changes nothing is not a move.';
                }
                $queue[] = $to;
            }
        }
        foreach ($cases as $case) {
            if (!isset($reached[$case->name])) {
                $wrong[] = $lifecycle . '::' . $case->name . ' cannot be reached from an initial state: nothing writes it, or a move is missing.';
            }
            if (!is_string($case->value)) {
                $wrong[] = $lifecycle . '::' . $case->name . ' is not stored as a string.';
            }
        }
        if (!in_array(\Prosper202\Lifecycle\Moves::class, self::traitsOf($lifecycle), true)) {
            $wrong[] = $lifecycle . ' does not use Prosper202\\Lifecycle\\Moves.';
        }

        return $wrong;
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private static function traitsOf(string $class): array
    {
        $traits = class_uses($class);

        return $traits === false ? [] : array_values($traits);
    }
}
