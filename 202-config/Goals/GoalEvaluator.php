<?php

declare(strict_types=1);

namespace Prosper202\Goals;

use Prosper202\Conversion\Ledger\Amount;

/**
 * The goal evaluator (plan §5.5): events in, outcomes out. Pure — no
 * database, no clock — so the same specification runs here, in the Swift
 * SDK (PR 8) and in the Kotlin SDK (PR 7), and all three are held to the
 * vectors in tests/fixtures/app-sdk-contract/goals/ (format and rules in
 * that directory's README.md, which is the specification; this class is
 * the reference implementation).
 *
 * The rules, per event, in the order the events sort (GoalEvent::compare:
 * effective time = min(occurred_at, received_at), then received_at, then
 * event id bytes):
 *
 * 1. Each goal is evaluated under ONE version: the newest whose
 *    effective_at <= the event's received_at, provided the event arrived in
 *    the goal's span [starts_at, ends_at); a re-evaluation's rebase raises
 *    that to at least the rebased version for every event. No version, no
 *    evaluation. A version whose definition is invalid, or whose `after`
 *    names a goal outside the set, is disabled with its reason and skipped.
 * 2. Goals are visited in `after` order (a prerequisite before the goals
 *    waiting on it; ties by goal id). Goals whose selected versions form a
 *    cycle are disabled (`cycle`) for that event.
 * 3. A goal counts the event when: its trigger matches (the install trigger
 *    matches only the install itself; an event trigger matches the name
 *    exactly and every `where` predicate); every goal in `after` has been
 *    reached eligibly, by an earlier event or earlier in this one; and the
 *    event's effective time t is inside the window, 0 <= t - anchor <
 *    days * 86400. A window whose anchor the subject lacks does not exclude
 *    the event: the outcomes are recorded as ineligible instead.
 * 4. Counting adds one to `count`; a `sum` threshold adds the property's
 *    value in units of 0.00001 (an event whose property is missing or not a
 *    number is not counted at all).
 * 5. The goal is reached for the n-th time when count >= n * N (or sum >=
 *    n * S), up to 1 time (`once`) or `max` times (`each`); one event can
 *    reach several n at once when a sum jumps.
 *
 * Nothing here decides money: an outcome carries the goal's own value, and
 * the campaign's payout, payability and revenue trust are applied by the
 * writer (GoalEngine).
 */
final class GoalEvaluator
{
    /**
     * Evaluate every event of a subject from nothing. For an install
     * subject the install itself is the first event (GoalEvent::install()).
     *
     * @param list<GoalSpec> $specs
     * @param list<GoalEvent> $events
     */
    public static function evaluateAll(array $specs, GoalSubject $subject, array $events): EvaluationResult
    {
        if ($subject->type === GoalSubject::INSTALL && $subject->installAt !== null) {
            $events[] = GoalEvent::install($subject->installAt);
        }

        return self::fold($specs, $subject, $events, new EvaluationState());
    }

    /**
     * Evaluate more events from a state a previous evaluation left. Every
     * new event must sort after every event the state has seen; the caller
     * (GoalEngine) checks that and runs evaluateAll() otherwise.
     *
     * @param list<GoalSpec> $specs
     * @param list<GoalEvent> $events
     */
    public static function continueFrom(array $specs, GoalSubject $subject, EvaluationState $state, array $events): EvaluationResult
    {
        return self::fold($specs, $subject, $events, $state);
    }

    /**
     * @param list<GoalSpec> $specs
     * @param list<GoalEvent> $events
     */
    private static function fold(array $specs, GoalSubject $subject, array $events, EvaluationState $state): EvaluationResult
    {
        $seen = [];
        foreach ($events as $event) {
            if (isset($seen[$event->eventId])) {
                throw new \InvalidArgumentException('event id "' . $event->eventId . '" appears twice for one subject');
            }
            $seen[$event->eventId] = true;
        }
        usort($events, [GoalEvent::class, 'compare']);

        $bySpec = [];
        foreach ($specs as $spec) {
            if (isset($bySpec[$spec->goalId])) {
                throw new \InvalidArgumentException('goal ' . $spec->goalId . ' appears twice in the goal set');
            }
            $bySpec[$spec->goalId] = $spec;
        }
        ksort($bySpec);

        // Parse every version once. A version that cannot be used is
        // disabled with its reason; the others still evaluate.
        $parsed = [];
        $disabled = [];
        foreach ($bySpec as $goalId => $spec) {
            foreach ($spec->versions as $v) {
                $version = (int) $v['version'];
                try {
                    $def = GoalDefinition::parse($v['definition'], $goalId);
                } catch (InvalidGoalDefinition) {
                    $disabled[$goalId . ':' . $version . ':invalid_definition'] = ['goal_id' => $goalId, 'version' => $version, 'reason' => 'invalid_definition'];
                    $parsed[$goalId][$version] = null;
                    continue;
                }
                foreach ($def->after as $prereq) {
                    if (!isset($bySpec[$prereq])) {
                        $disabled[$goalId . ':' . $version . ':prerequisite_missing'] = ['goal_id' => $goalId, 'version' => $version, 'reason' => 'prerequisite_missing'];
                        $def = null;
                        break;
                    }
                }
                $parsed[$goalId][$version] = $def;
            }
        }
        foreach ($subject->rebases as $goalId => $version) {
            if (isset($bySpec[$goalId]) && !array_key_exists((int) $version, $parsed[$goalId] ?? [])) {
                throw new \InvalidArgumentException('subject is rebased onto goal ' . $goalId . ' version ' . $version . ', which the goal set does not hold');
            }
        }

        $outcomes = [];
        foreach ($events as $event) {
            // Rule 1: one version per goal for this event.
            $selected = [];
            foreach ($bySpec as $goalId => $spec) {
                $version = self::versionFor($spec, $subject, $event);
                if ($version === null) {
                    continue;
                }
                $def = $parsed[$goalId][$version] ?? null;
                if ($def === null) {
                    continue; // disabled above, or a rebase to an unknown version (thrown above)
                }
                $selected[$goalId] = [$version, $def];
            }

            // Rule 2: prerequisites first.
            foreach (self::order($selected, $cyclic) as $goalId) {
                [$version, $def] = $selected[$goalId];
                foreach (self::step($goalId, $version, $def, $subject, $event, $state) as $outcome) {
                    $outcomes[] = $outcome;
                }
            }
            foreach ($cyclic as $goalId) {
                $version = $selected[$goalId][0];
                $disabled[$goalId . ':' . $version . ':cycle'] = ['goal_id' => $goalId, 'version' => $version, 'reason' => 'cycle'];
            }
        }

        $disabled = array_values($disabled);
        usort($disabled, static fn (array $a, array $b): int => [$a['goal_id'], $a['version'], $a['reason']] <=> [$b['goal_id'], $b['version'], $b['reason']]);

        return new EvaluationResult($outcomes, $disabled, $state);
    }

    /**
     * The version a goal evaluates an event under, or null when it does not
     * evaluate it (rule 1).
     */
    public static function versionFor(GoalSpec $spec, GoalSubject $subject, GoalEvent $event): ?int
    {
        if ($spec->endsAt !== null && $event->receivedAt >= $spec->endsAt) {
            return null;
        }
        $natural = null;
        if ($event->receivedAt >= $spec->startsAt) {
            foreach ($spec->versions as $v) {
                if ((int) $v['effective_at'] <= $event->receivedAt && ($natural === null || (int) $v['version'] > $natural)) {
                    $natural = (int) $v['version'];
                }
            }
        }
        $rebase = $subject->rebases[$spec->goalId] ?? null;
        if ($rebase !== null && ($natural === null || $rebase > $natural)) {
            return (int) $rebase;
        }

        return $natural;
    }

    /**
     * Kahn's algorithm over the selected goals' `after` edges, smallest goal
     * id first among the ready ones. Goals left over sit on a cycle.
     *
     * @param array<int, array{0: int, 1: GoalDefinition}> $selected
     * @param list<int> $cyclic out
     * @return list<int>
     */
    private static function order(array $selected, ?array &$cyclic): array
    {
        $waitingOn = [];
        $dependents = [];
        foreach ($selected as $goalId => [, $def]) {
            $waitingOn[$goalId] = 0;
            foreach ($def->after as $prereq) {
                if (isset($selected[$prereq])) {
                    $waitingOn[$goalId]++;
                    $dependents[$prereq][] = $goalId;
                }
            }
        }
        $ready = [];
        foreach ($waitingOn as $goalId => $n) {
            if ($n === 0) {
                $ready[] = $goalId;
            }
        }
        $order = [];
        while ($ready !== []) {
            sort($ready);
            $goalId = array_shift($ready);
            $order[] = $goalId;
            foreach ($dependents[$goalId] ?? [] as $dependent) {
                if (--$waitingOn[$dependent] === 0) {
                    $ready[] = $dependent;
                }
            }
        }
        $cyclic = [];
        foreach ($waitingOn as $goalId => $n) {
            if ($n > 0) {
                $cyclic[] = $goalId;
            }
        }
        sort($cyclic);

        return $order;
    }

    /**
     * Rules 3–5 for one goal version and one event.
     *
     * @return list<Outcome>
     */
    private static function step(int $goalId, int $version, GoalDefinition $def, GoalSubject $subject, GoalEvent $event, EvaluationState $state): array
    {
        if ($def->triggerInstall !== $event->isInstall) {
            return [];
        }
        if (!$def->triggerInstall) {
            if ($event->name !== $def->triggerEvent) {
                return [];
            }
            foreach ($def->where as $predicate) {
                if (!self::holds($predicate, $event)) {
                    return [];
                }
            }
        }
        foreach ($def->after as $prereq) {
            if (!isset($state->reached[$prereq])) {
                return [];
            }
        }

        $t = $event->effectiveAt();
        $ineligible = null;
        if ($def->withinDays !== null) {
            $anchor = $def->withinFrom === 'click' ? $subject->clickAt : $subject->installAt;
            if ($anchor === null) {
                $ineligible = $def->withinFrom === 'click' ? 'no_click' : 'no_install';
            } elseif ($t < $anchor || $t - $anchor >= $def->withinDays * 86400) {
                return [];
            }
        }

        $sumUnits = null;
        if ($def->thresholdKind === 'sum') {
            $sumUnits = self::units($event->property((string) $def->sumProp));
            if ($sumUnits === null) {
                return [];
            }
        }

        $p = &$state->entry($goalId, $version);
        $p['count']++;
        if ($sumUnits !== null) {
            $p['sum'] += $sumUnits;
        }

        $cap = $def->repeatMode === 'once' ? 1 : ($def->repeatMax ?? PHP_INT_MAX);
        $out = [];
        while ($p['times'] < $cap) {
            $next = $p['times'] + 1;
            $crossed = $def->thresholdKind === 'count'
                ? $p['count'] >= $next * $def->count
                : $p['sum'] >= $next * $def->sumGteUnits;
            if (!$crossed) {
                break;
            }
            $p['times'] = $next;
            $p['reached_at'] ??= $t;
            [$valueUnits, $source, $note] = self::value($def, $event);
            $out[] = new Outcome($goalId, $version, $next, $event->eventId, $t, $valueUnits, $source, $note, $ineligible);
            if ($ineligible === null) {
                $state->reached[$goalId] = true;
            }
        }
        unset($p);

        return $out;
    }

    /**
     * @param array{prop: string, op: string, value?: mixed} $predicate
     */
    private static function holds(array $predicate, GoalEvent $event): bool
    {
        $actual = $event->property($predicate['prop']);
        if ($predicate['op'] === 'exists') {
            return $actual !== null;
        }
        if ($actual === null) {
            return false;
        }
        $want = $predicate['value'] ?? null;

        return match ($predicate['op']) {
            'eq' => self::equal($actual, $want),
            'neq' => !self::equal($actual, $want),
            'in' => is_array($want) && self::inList($actual, $want),
            'gt', 'gte', 'lt', 'lte' => GoalDefinition::isNumber($actual) && GoalDefinition::isNumber($want)
                && match ($predicate['op']) {
                    'gt' => (float) $actual > (float) $want,
                    'gte' => (float) $actual >= (float) $want,
                    'lt' => (float) $actual < (float) $want,
                    default => (float) $actual <= (float) $want,
                },
            default => false,
        };
    }

    /** @param array<mixed> $list */
    private static function inList(mixed $actual, array $list): bool
    {
        foreach ($list as $item) {
            if (self::equal($actual, $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Typed equality: numbers compare by value (3 equals 3.0), strings byte
     * for byte, bools as bools; a string never equals a number ("3" is not
     * 3) and a bool never equals a number.
     */
    private static function equal(mixed $a, mixed $b): bool
    {
        if (GoalDefinition::isNumber($a) && GoalDefinition::isNumber($b)) {
            return (float) $a === (float) $b;
        }
        if (is_string($a) && is_string($b)) {
            return $a === $b;
        }
        if (is_bool($a) && is_bool($b)) {
            return $a === $b;
        }

        return false;
    }

    /**
     * A property value in units of 0.00001, or null when it is not a number
     * the ledger can hold.
     */
    private static function units(mixed $v): ?int
    {
        if (!GoalDefinition::isNumber($v)) {
            return null;
        }
        if (abs((float) $v) > 99999999999999.0) {
            return null;
        }
        try {
            return Amount::toUnits($v);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The goal's own value for an outcome reached by this event.
     *
     * @return array{0: int|null, 1: string, 2: string|null}
     */
    private static function value(GoalDefinition $def, GoalEvent $event): array
    {
        if ($def->valueType === 'fixed') {
            return [$def->valueUnits, Outcome::SOURCE_FIXED, null];
        }
        if ($def->valueType === 'none') {
            return [null, Outcome::SOURCE_NONE, null];
        }
        $raw = $event->property((string) $def->valueProp);
        if ($raw === null) {
            return [null, Outcome::SOURCE_PROPERTY, 'missing'];
        }
        if (!GoalDefinition::isNumber($raw)) {
            return [null, Outcome::SOURCE_PROPERTY, 'not_a_number'];
        }
        if ($raw < 0) {
            return [null, Outcome::SOURCE_PROPERTY, 'negative'];
        }
        $units = self::units($raw);
        if ($units === null || $units > GoalDefinition::MAX_AMOUNT_UNITS) {
            return [null, Outcome::SOURCE_PROPERTY, 'out_of_range'];
        }

        return [$units, Outcome::SOURCE_PROPERTY, null];
    }
}
