<?php

declare(strict_types=1);

namespace Api\V3\Apps\Apple;

/**
 * What a SKAN conversion value meant, over time, and so what a postback
 * that carries it decodes to (plan §5.5).
 *
 * An in-flight postback carries no version. A device sets a conversion value
 * with the schema document it last fetched, and Apple delivers the postback
 * up to 35 days after the install (the third conversion window closes on
 * day 35); an encoding edited today is still being applied by devices that
 * fetched the old document. So every meaning an encoding has had is kept
 * with the span it applied for — 202_app_skan_encodings holds the current
 * one (in force since `effective_at`), 202_app_skan_encoding_history every
 * one it replaced (until `retired_at`) — and a postback received at R is
 * decoded under every meaning the value had at any instant of
 * [R - HORIZON, R]:
 *
 *  - one meaning: the postback decodes to it;
 *  - two or more that disagree (another goal, or another revenue_override):
 *    `ambiguous`, credited to neither — the report cannot know which
 *    document the device held;
 *  - none: no meaning existed inside the horizon. The first meaning the
 *    value was given AFTER R decodes it, so an encoding added once
 *    postbacks are already arriving still reads them (what the report has
 *    always done); a value never given one is undecoded.
 *
 * "At an instant" is the report's existing resolution: the claiming
 * registration's own encoding wins over the account-wide one
 * (registration_id 0), and a fine value never falls back to a coarse one.
 * A meaning is identified by its goal and its revenue_override, which is
 * all a decode reports.
 *
 * Pure: the report loads the meanings once and asks per group of rows. The
 * answer only changes where R crosses an effective_at, a retired_at, or one
 * of those plus the horizon; breakpoints() lists them, so the report groups
 * its rows by the segment between two breakpoints (MySQL's INTERVAL()) and
 * decodes each segment once, at representativeTime().
 */
final class SkanEncodingTimeline
{
    public const HORIZON_DAYS = 35;
    public const HORIZON_SECONDS = self::HORIZON_DAYS * 86400;

    public const DECODED = 'decoded';
    public const AMBIGUOUS = 'ambiguous';
    public const UNDECODED = 'undecoded';

    /**
     * slot key "<registration>|<kind>|<value>" => spans.
     *
     * @var array<string, list<array{from: int, until: int|null, goal_id: int, revenue_override: string|null}>>
     */
    private array $slots = [];

    /** @var list<int> */
    private array $breakpoints = [];

    /**
     * @param list<array{registration_id: int, fine_value: int|null, coarse_value: string|null, goal_id: int,
     *                   revenue_override: string|null, effective_at: int, retired_at: int|null}> $meanings
     *        Current encodings have retired_at null; history rows have it set.
     */
    public function __construct(array $meanings)
    {
        $points = [];
        foreach ($meanings as $m) {
            $key = self::slotKey((int) $m['registration_id'], $m['fine_value'], $m['coarse_value']);
            if ($key === null) {
                continue; // no value at all: nothing can decode through it
            }
            $from = (int) $m['effective_at'];
            $until = $m['retired_at'] === null ? null : (int) $m['retired_at'];
            if ($until !== null && $until <= $from) {
                continue; // replaced in the second it began: it applied at no instant
            }
            $this->slots[$key][] = [
                'from' => $from,
                'until' => $until,
                'goal_id' => (int) $m['goal_id'],
                'revenue_override' => self::normalizeAmount($m['revenue_override']),
            ];
            foreach ([$from, $until] as $t) {
                if ($t !== null) {
                    $points[$t] = true;
                    $points[$t + self::HORIZON_SECONDS] = true;
                }
            }
        }
        $this->breakpoints = array_map('intval', array_keys($points));
        sort($this->breakpoints);
    }

    /**
     * Sorted, distinct times at which some decode can change. A report groups
     * rows by INTERVAL(received_at, ...these), whose answer k means
     * breakpoints[k-1] <= received_at < breakpoints[k].
     *
     * @return list<int>
     */
    public function breakpoints(): array
    {
        return $this->breakpoints;
    }

    /**
     * A received_at inside segment $k (INTERVAL()'s answer), at which the
     * whole segment decodes the same.
     */
    public function representativeTime(int $segment): int
    {
        if ($this->breakpoints === []) {
            return 0;
        }
        if ($segment <= 0) {
            return $this->breakpoints[0] - 1;
        }

        return $this->breakpoints[min($segment, count($this->breakpoints)) - 1];
    }

    /**
     * Decode one value, received at $receivedAt by a postback that
     * $registrationId claimed (0: none claimed it).
     *
     * @return array{status: string, goal_id: int|null, revenue_override: string|null, meanings: int}
     *         meanings: how many distinct meanings the horizon held (2+ when ambiguous)
     */
    public function decode(int $registrationId, ?int $fine, ?string $coarse, int $receivedAt): array
    {
        $slots = [];
        if ($registrationId > 0) {
            $app = self::slotKey($registrationId, $fine, $coarse);
            if ($app !== null) {
                $slots[] = $this->slots[$app] ?? [];
            }
        }
        $account = self::slotKey(0, $fine, $coarse);
        if ($account === null) {
            return self::answer(self::UNDECODED, null, 0);
        }
        $slots[] = $this->slots[$account] ?? [];

        // Every instant at which the resolution inside the horizon can
        // change: its start, and every span edge inside (start, R].
        $start = $receivedAt - self::HORIZON_SECONDS;
        $instants = [$start => true];
        foreach ($slots as $spans) {
            foreach ($spans as $span) {
                foreach ([$span['from'], $span['until']] as $t) {
                    if ($t !== null && $t > $start && $t <= $receivedAt) {
                        $instants[$t] = true;
                    }
                }
            }
        }

        $distinct = [];
        foreach (array_keys($instants) as $t) {
            $meaning = self::resolveAt($slots, (int) $t);
            if ($meaning !== null) {
                $distinct[self::identity($meaning)] = $meaning;
            }
        }
        if (count($distinct) === 1) {
            return self::answer(self::DECODED, array_values($distinct)[0], 1);
        }
        if (count($distinct) > 1) {
            return self::answer(self::AMBIGUOUS, null, count($distinct));
        }

        // Nothing in the horizon: the first meaning given after R.
        $later = [];
        foreach ($slots as $spans) {
            foreach ($spans as $span) {
                if ($span['from'] > $receivedAt) {
                    $later[$span['from']] = true;
                }
            }
        }
        $times = array_map('intval', array_keys($later));
        sort($times);
        foreach ($times as $t) {
            $meaning = self::resolveAt($slots, $t);
            if ($meaning !== null) {
                return self::answer(self::DECODED, $meaning, 1);
            }
        }

        return self::answer(self::UNDECODED, null, 0);
    }

    /**
     * The meaning in force at $t: the first slot (the registration's own,
     * then the account-wide) with a span covering it.
     *
     * @param list<list<array{from: int, until: int|null, goal_id: int, revenue_override: string|null}>> $slots
     * @return array{goal_id: int, revenue_override: string|null}|null
     */
    private static function resolveAt(array $slots, int $t): ?array
    {
        foreach ($slots as $spans) {
            foreach ($spans as $span) {
                if ($span['from'] <= $t && ($span['until'] === null || $t < $span['until'])) {
                    return ['goal_id' => $span['goal_id'], 'revenue_override' => $span['revenue_override']];
                }
            }
        }

        return null;
    }

    /** @param array{goal_id: int, revenue_override: string|null} $meaning */
    private static function identity(array $meaning): string
    {
        // Length-prefixed, so no goal id and override can collide with
        // another pair by moving a delimiter (CLAUDE.md #17).
        $override = $meaning['revenue_override'] ?? '';

        return $meaning['goal_id'] . ':' . ($meaning['revenue_override'] === null ? 'n' : strlen($override) . ':' . $override);
    }

    /**
     * @param array{goal_id: int, revenue_override: string|null}|null $meaning
     * @return array{status: string, goal_id: int|null, revenue_override: string|null, meanings: int}
     */
    private static function answer(string $status, ?array $meaning, int $meanings): array
    {
        return [
            'status' => $status,
            'goal_id' => $meaning['goal_id'] ?? null,
            'revenue_override' => $meaning['revenue_override'] ?? null,
            'meanings' => $meanings,
        ];
    }

    /** "<registration>|fine|<n>" or "<registration>|coarse|<word>"; null for no value. */
    private static function slotKey(int $registrationId, mixed $fine, mixed $coarse): ?string
    {
        if ($fine !== null) {
            return $registrationId . '|fine|' . (int) $fine;
        }
        if ($coarse !== null) {
            return $registrationId . '|coarse|' . (string) $coarse;
        }

        return null;
    }

    /** decimal(11,5) as MySQL returns it, or null: one spelling per amount. */
    private static function normalizeAmount(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return \Prosper202\Conversion\Ledger\Amount::fromUnits(\Prosper202\Conversion\Ledger\Amount::toUnits((string) $value));
    }
}
