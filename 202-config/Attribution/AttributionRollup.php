<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Database\Connection;
use Prosper202\Report\RollupDirty;

/**
 * The report rollup (plan §7.3; built in PR 13): the sums AttributionReports
 * reads, kept per account, part, dimension, model and hour in
 * 202_attribution_rollup, plus one row per UTC day summed from its 24 hours:
 * the breakdowns' credits, cost, assists and totals, and the journey
 * metrics' counts (PART_JOURNEYS).
 *
 * What a report may read from it, and why that is always the same answer as
 * the full computation, rests on four rules:
 *
 * 1. **Built.** Every hour below the account's built_through_hour has been
 *    summed (an hour with no data has no rows). Only sealed hours are built —
 *    hours that ended more than SEAL_SECONDS ago — because a click is stored
 *    with the time of the request that recorded it, so a new click can only
 *    land in an hour that is not built yet.
 * 2. **Dirty.** Anything that changes what an already-built hour sums writes
 *    a 202_attribution_rollup_dirty row for it *in the same transaction as
 *    the change*: the worker for every journey and credit it rewrites
 *    (AttributionStore), the CPC tools for the range they update
 *    (RollupDirty). A change to one click whose hours the writer cannot
 *    cheaply know — a rotator re-click rewriting an existing click — writes
 *    a 202_attribution_rollup_dirty_clicks row instead, and this class turns
 *    it into the hours of the click and of every conversion whose journey
 *    holds it. A report computes every dirty hour exactly, and computes the
 *    whole account exactly while any changed click is unresolved.
 * 3. **One snapshot.** Each report statement unions the rollup rows with the
 *    exact rows for the hours it cannot serve, and carries a guard evaluated
 *    in the same statement: the account is still built past the planned
 *    hours, none of them is dirty, no click is unresolved and (for the
 *    effective model) the overrides are the ones the rows were summed under.
 *    A failed guard re-plans. The rebuild reads its data after it reads the
 *    dirty rows it will consume, and deletes only those, so a change that
 *    commits while it runs leaves its own dirty row behind.
 * 4. **Only what cannot change without a mark.** Names are looked up when a
 *    report runs, never stored. The effective rows are summed under the
 *    account's per-campaign overrides and default, recorded beside them; a
 *    report reads them only while the live overrides and the default it was
 *    asked for are exactly those, and sync() marks every built hour dirty
 *    when they change.
 *
 * The rollup is maintained by the attribution worker's run (it holds the
 * worker lock, so no credit changes while it sums) and needs no schedule of
 * its own.
 */
final class AttributionRollup
{
    public const PART_CREDITS = 1;
    public const PART_COST = 2;
    public const PART_ASSISTS = 3;
    public const PART_TOTALS = 4;
    /**
     * The journey metrics' counts (AttributionReports::journeyMetrics()),
     * over conversions by conv_time: one "dimension" per count, each row a
     * number of conversions (n) for one key. Not per model: a journey is
     * the same under every model.
     */
    public const PART_JOURNEYS = 5;

    /** Conversions by journey length; the key is the number of touches. */
    public const JOURNEY_LENGTH = 1;
    /** Truncated journeys (the touch cap cut them), keyed by touches. */
    public const JOURNEY_TRUNCATED = 2;
    /** Journeys whose converting click had no visitor key, keyed by touches. */
    public const JOURNEY_UNIDENTIFIED = 3;
    /** Conversions by time-to-convert bucket (its position in AttributionReports' list). */
    public const JOURNEY_TIME_TO_CONVERT = 4;
    /** Conversions by the converting click's browser id (key_null: no clicks_advance row). */
    public const JOURNEY_BROWSER = 5;
    /** One-touch conversions by the converting click's browser id. */
    public const JOURNEY_BROWSER_ONE_TOUCH = 6;

    public const GRAIN_HOUR = 0;
    public const GRAIN_DAY = 1;
    /**
     * One row per account (part PART_JOURNEYS, bucket 0) saying the
     * account's built hours carry the journey part: written with the state
     * row, or — for a rollup summed before the part existed — together with
     * a mark on every built hour, so no clean hour lacks journey rows.
     * Reports read the part only while it exists. No hour or day build
     * touches this grain.
     */
    public const GRAIN_MARKER = 2;

    /** model_id of the effective rows, and of the parts no model shapes. */
    public const EFFECTIVE = 0;

    /** An hour is built once it ended this long ago. */
    public const SEAL_SECONDS = 7200;

    /** The report dimensions (AttributionReports::dimensions()) as stored. */
    public const DIMENSION_CODES = [
        'campaign' => 1,
        'traffic_source' => 2,
        'landing_page' => 3,
        'keyword' => 4,
        'c1' => 5,
        'c2' => 6,
        'c3' => 7,
        'c4' => 8,
        'country' => 9,
        'device' => 10,
        'day' => 11,
    ];

    private const INSERT_CHUNK = 500;
    private const CLICK_BATCH = 500;
    private const DIRTY_BATCH = 5000;

    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(private Connection $conn, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * One maintenance pass: resolve changed clicks, re-sync every account's
     * overrides, re-sum dirty hours, then build newly sealed hours, until
     * the budget runs out. Whatever is left is picked up by the next run;
     * reports compute it exactly meanwhile.
     */
    public function run(int $budgetSeconds): RollupReport
    {
        $report = new RollupReport();
        $deadline = ($this->clock)() + max(1, $budgetSeconds);

        $report->clicksResolved = $this->resolveDirtyClicks($deadline);
        foreach ($this->accounts() as $userId) {
            if (($this->clock)() >= $deadline) {
                break;
            }
            $this->sync($userId);
            try {
                $report->hoursRebuilt += $this->rebuildDirty($userId, $deadline);
                $report->hoursBuilt += $this->advance($userId, $deadline);
            } catch (RollupOutOfSync) {
                // An override changed after this run's sync: nothing was
                // written for this account; the next run syncs and goes on.
                $report->accountsDeferred++;
            }
        }

        return $report;
    }

    /** @return list<int> every account with attribution models */
    public function accounts(): array
    {
        $stmt = $this->conn->prepareWrite('SELECT DISTINCT user_id FROM 202_attribution_models ORDER BY user_id');

        return array_map(static fn (array $r): int => (int) $r['user_id'], $this->conn->fetchAll($stmt));
    }

    /**
     * Turn changed clicks into dirty hours: the hours of every row the click
     * has (the cost side), and the conversion hours of every journey that
     * holds it (credits and assists), then drop the click row — in one
     * transaction, so a report sees either the click or its hours.
     */
    public function resolveDirtyClicks(int $deadline): int
    {
        $resolved = 0;
        while (($this->clock)() < $deadline) {
            $stmt = $this->conn->prepareWrite(
                'SELECT dirty_id, user_id, click_id FROM 202_attribution_rollup_dirty_clicks ORDER BY dirty_id LIMIT ?'
            );
            $this->conn->bind($stmt, 'i', [self::CLICK_BATCH]);
            $rows = $this->conn->fetchAll($stmt);
            if ($rows === []) {
                break;
            }
            $this->conn->transaction(function () use ($rows): void {
                foreach ($rows as $row) {
                    $clickId = (int) $row['click_id'];
                    $marks = [];
                    $stmt = $this->conn->prepareWrite('SELECT DISTINCT user_id, click_time DIV 3600 AS h FROM 202_clicks WHERE click_id = ?');
                    $this->conn->bind($stmt, 'i', [$clickId]);
                    foreach ($this->conn->fetchAll($stmt) as $r) {
                        $marks[] = [(int) $r['user_id'], (int) $r['h']];
                    }
                    $stmt = $this->conn->prepareWrite(
                        'SELECT DISTINCT jm.user_id, jm.conv_time DIV 3600 AS h
                         FROM 202_attribution_journeys j JOIN 202_attribution_journey_meta jm ON jm.conv_id = j.conv_id
                         WHERE j.click_id = ?'
                    );
                    $this->conn->bind($stmt, 'i', [$clickId]);
                    foreach ($this->conn->fetchAll($stmt) as $r) {
                        $marks[] = [(int) $r['user_id'], (int) $r['h']];
                    }
                    foreach ($marks as [$userId, $hour]) {
                        // An account the rollup has never summed (or whose
                        // state a user deletion removed) has no hours to
                        // spoil; a mark there would never be consumed.
                        if ($this->state($userId) !== null) {
                            RollupDirty::hours($this->conn, $userId, $hour, $hour);
                        }
                    }
                    $del = $this->conn->prepareWrite('DELETE FROM 202_attribution_rollup_dirty_clicks WHERE dirty_id = ?');
                    $this->conn->bind($del, 'i', [(int) $row['dirty_id']]);
                    $this->conn->executeUpdate($del);
                }
            });
            $resolved += count($rows);
            if (count($rows) < self::CLICK_BATCH) {
                break;
            }
        }

        return $resolved;
    }

    /**
     * Record the overrides and default the effective rows are summed under.
     * When they changed, every built hour is marked dirty in the same
     * transaction: until each is re-summed a report computes it exactly.
     */
    public function sync(int $userId): void
    {
        $this->conn->transaction(function () use ($userId): void {
            $current = $this->currentOverrides($userId);
            $default = $this->currentDefault($userId);

            $stmt = $this->conn->prepareWrite(
                'SELECT built_through_hour, default_model_id FROM 202_attribution_rollup_state WHERE user_id = ? FOR UPDATE'
            );
            $this->conn->bind($stmt, 'i', [$userId]);
            $state = $this->conn->fetchOne($stmt);

            if ($state === null) {
                $ins = $this->conn->prepareWrite(
                    'INSERT INTO 202_attribution_rollup_state (user_id, built_through_hour, default_model_id, updated_at) VALUES (?, 0, ?, ?)'
                );
                $this->conn->bind($ins, 'iii', [$userId, $default, ($this->clock)()]);
                $this->conn->executeUpdate($ins);
                $this->storeOverrides($userId, $current);
                $this->markJourneysKept($userId);

                return;
            }

            if (!$this->journeysKept($userId)) {
                // Summed before the rollup kept the journey part: every
                // built hour lacks it until it is summed again.
                $this->markJourneysKept($userId);
                $built = (int) $state['built_through_hour'];
                if ($built > 0) {
                    RollupDirty::hours($this->conn, $userId, 0, $built - 1);
                }
            }

            $stored = $this->storedOverrides($userId);
            $storedDefault = $state['default_model_id'] !== null ? (int) $state['default_model_id'] : null;
            if ($stored === $current && $storedDefault === $default) {
                return;
            }

            $this->storeOverrides($userId, $current);
            $upd = $this->conn->prepareWrite(
                'UPDATE 202_attribution_rollup_state SET default_model_id = ?, updated_at = ? WHERE user_id = ?'
            );
            $this->conn->bind($upd, 'iii', [$default, ($this->clock)(), $userId]);
            $this->conn->executeUpdate($upd);
            $built = (int) $state['built_through_hour'];
            if ($built > 0) {
                RollupDirty::hours($this->conn, $userId, 0, $built - 1);
            }
        });
    }

    /**
     * Re-sum the account's dirty hours below built_through_hour, a UTC day
     * at a time, oldest first. Each day's transaction deletes the dirty rows
     * it has fully covered and moves the start of the rest past it; rows it
     * never read are left alone.
     *
     * @return int hours re-summed
     */
    public function rebuildDirty(int $userId, int $deadline): int
    {
        $state = $this->state($userId);
        if ($state === null) {
            return 0;
        }
        $built = $state['built'];

        // A row wholly at or above the built frontier marks hours that are
        // not summed yet: the build reads their data when it gets there. The
        // worker writes one per conversion it processes, so they go in one
        // statement rather than a batch at a time.
        $del = $this->conn->prepareWrite('DELETE FROM 202_attribution_rollup_dirty WHERE user_id = ? AND hour_from >= ?');
        $this->conn->bind($del, 'ii', [$userId, $built]);
        $this->conn->executeUpdate($del);

        $count = 0;
        while (($this->clock)() < $deadline) {
            $stmt = $this->conn->prepareWrite(
                'SELECT dirty_id, hour_from, hour_to FROM 202_attribution_rollup_dirty
                 WHERE user_id = ? AND hour_from < ? ORDER BY hour_from, dirty_id LIMIT ?'
            );
            $this->conn->bind($stmt, 'iii', [$userId, $built, self::DIRTY_BATCH]);
            $rows = array_map(static fn (array $r): array => [
                'id' => (int) $r['dirty_id'],
                'from' => (int) $r['hour_from'],
                'to' => (int) $r['hour_to'],
            ], $this->conn->fetchAll($stmt));
            if ($rows === []) {
                break;
            }
            $count += $this->rebuildRows($userId, $built, $rows, $deadline);
            if (count($rows) < self::DIRTY_BATCH) {
                break;
            }
        }

        return $count;
    }

    /**
     * Re-sum the hours a batch of dirty rows names, a UTC day at a time, and
     * consume the rows in the same transactions.
     *
     * @param list<array{id: int, from: int, to: int}> $rows
     * @return int hours re-summed
     */
    private function rebuildRows(int $userId, int $built, array $rows, int $deadline): int
    {
        $count = 0;
        while ($rows !== [] && ($this->clock)() < $deadline) {
            $start = min(array_column($rows, 'from'));
            $last = min($built - 1, max(array_column($rows, 'to')));
            $next = $this->nextActiveHour($userId, $start);
            if ($next === null || $next > $last) {
                // Nothing to sum from $start to the last dirty hour: no data
                // and no rollup rows there. Every row is consumed as it is.
                $this->conn->transaction(function () use (&$rows, $last, $built): void {
                    $rows = $this->consume($rows, $last, $built);
                });
                break;
            }
            // The hours from $start up to $next are empty; sum the dirty
            // hours of $next's day.
            $chunkEnd = min(intdiv($next, 24) * 24 + 23, $last);
            $hours = $this->dirtyHoursWithin($rows, $next, $chunkEnd);
            $this->conn->transaction(function () use ($userId, $hours, &$rows, $chunkEnd, $built): void {
                $default = $this->buildDefault($userId);
                foreach ($hours as [$a, $b]) {
                    $this->buildHours($userId, $a, $b, $default);
                }
                $day = intdiv($chunkEnd, 24);
                if ($hours !== [] && ($day + 1) * 24 <= $built) {
                    $this->buildDay($userId, $day);
                }
                $rows = $this->consume($rows, $chunkEnd, $built);
            });
            foreach ($hours as [$a, $b]) {
                $count += $b - $a + 1;
            }
        }

        return $count;
    }

    /**
     * Sum the account's newly sealed hours, a UTC day at a time, skipping
     * stretches with no data, and move built_through_hour past them in the
     * same transaction.
     *
     * @return int hours summed
     */
    public function advance(int $userId, int $deadline): int
    {
        $sealed = intdiv(($this->clock)() - self::SEAL_SECONDS, 3600);
        $count = 0;
        while (($this->clock)() < $deadline) {
            $state = $this->state($userId);
            if ($state === null) {
                return $count;
            }
            $built = $state['built'];
            if ($built >= $sealed) {
                break;
            }
            $next = $this->nextActiveHour($userId, $built);
            $this->conn->transaction(function () use ($userId, $built, $next, $sealed, &$count): void {
                // The frontier is re-read under a lock: a second builder
                // (there is none while the worker lock is held) could not
                // move it twice.
                $stmt = $this->conn->prepareWrite(
                    'SELECT built_through_hour FROM 202_attribution_rollup_state WHERE user_id = ? FOR UPDATE'
                );
                $this->conn->bind($stmt, 'i', [$userId]);
                $row = $this->conn->fetchOne($stmt);
                if ($row === null || (int) $row['built_through_hour'] !== $built) {
                    return;
                }
                $firstDay = intdiv($built, 24);
                if ($next === null || $next >= $sealed) {
                    $newBuilt = $sealed;
                } else {
                    $newBuilt = min($sealed, intdiv($next, 24) * 24 + 24);
                    $this->buildHours($userId, $next, $newBuilt - 1, $this->buildDefault($userId));
                    $count += $newBuilt - $next;
                }
                // Every day the frontier moved past gets its day rows. Only
                // the day it started in and the day it built can hold hour
                // rows; the days between were empty.
                $days = array_unique(array_filter(
                    [$firstDay, intdiv($newBuilt - 1, 24)],
                    static fn (int $d): bool => ($d + 1) * 24 <= $newBuilt && ($d + 1) * 24 > $built
                ));
                foreach ($days as $day) {
                    $this->buildDay($userId, $day);
                }
                $upd = $this->conn->prepareWrite(
                    'UPDATE 202_attribution_rollup_state SET built_through_hour = ?, updated_at = ? WHERE user_id = ?'
                );
                $this->conn->bind($upd, 'iii', [$newBuilt, ($this->clock)(), $userId]);
                $this->conn->executeUpdate($upd);
            });
            if ($next === null || $next >= $sealed) {
                break;
            }
        }

        return $count;
    }

    /**
     * Re-sum hours [$hourFrom, $hourTo] of an account: every part, every
     * dimension, every model and the effective rows. Runs inside the
     * caller's transaction, after the caller has read the dirty rows it
     * will consume.
     */
    public function buildHours(int $userId, int $hourFrom, int $hourTo, ?int $defaultModelId): void
    {
        $del = $this->conn->prepareWrite(
            'DELETE FROM 202_attribution_rollup WHERE user_id = ? AND grain = ? AND bucket BETWEEN ? AND ?'
        );
        $this->conn->bind($del, 'iiii', [$userId, self::GRAIN_HOUR, $hourFrom, $hourTo]);
        $this->conn->executeUpdate($del);

        $from = $hourFrom * 3600;
        $to = $hourTo * 3600 + 3599;

        $stmt = $this->conn->prepareWrite('SELECT model_id FROM 202_attribution_models WHERE user_id = ? ORDER BY model_id');
        $this->conn->bind($stmt, 'i', [$userId]);
        $modelIds = array_map(static fn (array $r): int => (int) $r['model_id'], $this->conn->fetchAll($stmt));

        $rows = [];
        foreach (self::DIMENSION_CODES as $dimension => $code) {
            [$key, $joins] = self::keySql($dimension, 'cr.conv_time');
            if ($modelIds !== []) {
                $in = self::intList($modelIds);
                $stmt = $this->conn->prepareWrite(
                    "SELECT cr.model_id AS m, cr.conv_time DIV 3600 AS b, MIN(($key) IS NULL) AS kn, COALESCE($key, 0) AS k,
                            COUNT(*) AS n, SUM(cr.credit) AS credit, SUM(cr.revenue) AS revenue
                     FROM 202_attribution_credits cr
                     JOIN 202_clicks c ON c.click_id = cr.click_id
                     $joins
                     WHERE cr.model_id IN ($in) AND cr.conv_time >= ? AND cr.conv_time <= ?
                     GROUP BY m, b, k"
                );
                $this->conn->bind($stmt, 'ii', [$from, $to]);
                foreach ($this->conn->fetchAll($stmt) as $r) {
                    $rows[] = [self::PART_CREDITS, $code, (int) $r['m'], (int) $r['b'], (int) $r['kn'], (string) $r['k'], (int) $r['n'], (string) $r['credit'], (string) $r['revenue'], '0'];
                }
            }
            if ($defaultModelId !== null) {
                [$source, $where] = self::effectiveSource($userId, $defaultModelId, array_merge($modelIds, [$defaultModelId]), "cr.conv_time BETWEEN $from AND $to");
                $stmt = $this->conn->prepareWrite(
                    "SELECT cr.conv_time DIV 3600 AS b, MIN(($key) IS NULL) AS kn, COALESCE($key, 0) AS k,
                            COUNT(*) AS n, SUM(cr.credit) AS credit, SUM(cr.revenue) AS revenue
                     FROM $source
                     JOIN 202_clicks c ON c.click_id = cr.click_id
                     $joins
                     WHERE $where
                     GROUP BY b, k"
                );
                foreach ($this->conn->fetchAll($stmt) as $r) {
                    $rows[] = [self::PART_CREDITS, $code, self::EFFECTIVE, (int) $r['b'], (int) $r['kn'], (string) $r['k'], (int) $r['n'], (string) $r['credit'], (string) $r['revenue'], '0'];
                }
            }

            [$key, $joins] = self::keySql($dimension, 'c.click_time');
            $stmt = $this->conn->prepareWrite(
                "SELECT c.click_time DIV 3600 AS b, MIN(($key) IS NULL) AS kn, COALESCE($key, 0) AS k,
                        COUNT(*) AS n, SUM(c.click_cpc) AS cost
                 FROM 202_clicks c
                 $joins
                 WHERE c.user_id = ? AND c.click_time >= ? AND c.click_time <= ? AND c.click_bot = 0
                 GROUP BY b, k"
            );
            $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);
            foreach ($this->conn->fetchAll($stmt) as $r) {
                $rows[] = [self::PART_COST, $code, self::EFFECTIVE, (int) $r['b'], (int) $r['kn'], (string) $r['k'], (int) $r['n'], '0', '0', (string) $r['cost']];
            }

            [$key, $joins] = self::keySql($dimension, 'jm.conv_time');
            $stmt = $this->conn->prepareWrite(
                "SELECT jm.conv_time DIV 3600 AS b, MIN(($key) IS NULL) AS kn, COALESCE($key, 0) AS k,
                        COUNT(DISTINCT j.conv_id) AS n
                 FROM 202_attribution_journey_meta jm
                 JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position + 1 < jm.touches
                 JOIN 202_clicks c ON c.click_id = j.click_id
                 $joins
                 WHERE jm.user_id = ? AND jm.conv_time >= ? AND jm.conv_time <= ?
                 GROUP BY b, k"
            );
            $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);
            foreach ($this->conn->fetchAll($stmt) as $r) {
                $rows[] = [self::PART_ASSISTS, $code, self::EFFECTIVE, (int) $r['b'], (int) $r['kn'], (string) $r['k'], (int) $r['n'], '0', '0', '0'];
            }
        }

        if ($modelIds !== []) {
            $in = self::intList($modelIds);
            $stmt = $this->conn->prepareWrite(
                "SELECT cr.model_id AS m, cr.conv_time DIV 3600 AS b, COUNT(DISTINCT cr.conv_id) AS n,
                        SUM(cr.credit) AS credit, SUM(cr.revenue) AS revenue
                 FROM 202_attribution_credits cr
                 WHERE cr.model_id IN ($in) AND cr.conv_time >= ? AND cr.conv_time <= ?
                 GROUP BY m, b"
            );
            $this->conn->bind($stmt, 'ii', [$from, $to]);
            foreach ($this->conn->fetchAll($stmt) as $r) {
                $rows[] = [self::PART_TOTALS, 0, (int) $r['m'], (int) $r['b'], 0, '0', (int) $r['n'], (string) $r['credit'], (string) $r['revenue'], '0'];
            }
        }
        if ($defaultModelId !== null) {
            [$source, $where] = self::effectiveSource($userId, $defaultModelId, array_merge($modelIds, [$defaultModelId]), "cr.conv_time BETWEEN $from AND $to");
            $stmt = $this->conn->prepareWrite(
                "SELECT cr.conv_time DIV 3600 AS b, COUNT(DISTINCT cr.conv_id) AS n, SUM(cr.credit) AS credit, SUM(cr.revenue) AS revenue
                 FROM $source WHERE $where GROUP BY b"
            );
            foreach ($this->conn->fetchAll($stmt) as $r) {
                $rows[] = [self::PART_TOTALS, 0, self::EFFECTIVE, (int) $r['b'], 0, '0', (int) $r['n'], (string) $r['credit'], (string) $r['revenue'], '0'];
            }
        }

        array_push($rows, ...$this->journeyRows($userId, $from, $to));

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            $values = [];
            foreach ($chunk as $r) {
                array_push($values, $userId, $r[0], $r[1], $r[2], self::GRAIN_HOUR, $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], $r[9]);
            }
            $stmt = $this->conn->prepareWrite(
                'INSERT INTO 202_attribution_rollup
                    (user_id, part, dim, model_id, grain, bucket, key_null, dim_key, n, credit, revenue, cost) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'))
            );
            $this->conn->bind($stmt, str_repeat('iiiiiiisisss', count($chunk)), $values);
            $this->conn->executeUpdate($stmt);
        }

        // An hour whose journeys hold a click younger than the seal (a
        // conversion dated before its own click can) is summed but left
        // dirty, so reports compute it exactly until that click is old:
        // the redirects that rewrite a young click do not mark it
        // (RollupDirty::HOT_PATH_SECONDS), which is only sound if no clean
        // hour holds one.
        $stmt = $this->conn->prepareWrite(
            'SELECT DISTINCT jm.conv_time DIV 3600 AS h
             FROM 202_attribution_journey_meta jm
             JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id
             JOIN 202_clicks c ON c.click_id = j.click_id
             WHERE jm.user_id = ? AND jm.conv_time >= ? AND jm.conv_time <= ? AND c.click_time > ?'
        );
        $this->conn->bind($stmt, 'iiii', [$userId, $from, $to, ($this->clock)() - self::SEAL_SECONDS]);
        foreach ($this->conn->fetchAll($stmt) as $r) {
            RollupDirty::hours($this->conn, $userId, (int) $r['h'], (int) $r['h']);
        }
    }

    /**
     * The journey part's hour rows for [$from, $to] (seconds): the same
     * counts, over the same joins, as AttributionReports' full computation.
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int, 5: string, 6: int, 7: string, 8: string, 9: string}>
     */
    private function journeyRows(int $userId, int $from, int $to): array
    {
        $rows = [];
        $row = static fn (int $dim, int $bucket, int $keyNull, string $key, int $n): array => [self::PART_JOURNEYS, $dim, self::EFFECTIVE, $bucket, $keyNull, $key, $n, '0', '0', '0'];

        $stmt = $this->conn->prepareWrite(
            'SELECT jm.conv_time DIV 3600 AS b, jm.touches AS k, COUNT(*) AS n,
                    SUM(jm.truncated) AS truncated, SUM(jm.identified = 0) AS unidentified
             FROM 202_attribution_journey_meta jm
             WHERE jm.user_id = ? AND jm.conv_time >= ? AND jm.conv_time <= ?
             GROUP BY b, k'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $b = (int) $r['b'];
            $k = (string) $r['k'];
            $rows[] = $row(self::JOURNEY_LENGTH, $b, 0, $k, (int) $r['n']);
            if ((int) $r['truncated'] > 0) {
                $rows[] = $row(self::JOURNEY_TRUNCATED, $b, 0, $k, (int) $r['truncated']);
            }
            if ((int) $r['unidentified'] > 0) {
                $rows[] = $row(self::JOURNEY_UNIDENTIFIED, $b, 0, $k, (int) $r['unidentified']);
            }
        }

        $stmt = $this->conn->prepareWrite(
            'SELECT jm.conv_time DIV 3600 AS b, ' . AttributionReports::timeToConvertSql('jm.conv_time', 'j.click_time', true) . ' AS k, COUNT(*) AS n
             FROM 202_attribution_journey_meta jm
             JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position = 0
             WHERE jm.user_id = ? AND jm.conv_time >= ? AND jm.conv_time <= ?
             GROUP BY b, k'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $rows[] = $row(self::JOURNEY_TIME_TO_CONVERT, (int) $r['b'], 0, (string) $r['k'], (int) $r['n']);
        }

        // Grouped by whether the browser is NULL as well as by its id: the
        // report names a NULL 'Unknown' and an id by its row, so the two
        // must not share a row the way a breakdown's NULL and 0 keys do.
        $stmt = $this->conn->prepareWrite(
            'SELECT jm.conv_time DIV 3600 AS b, ca.browser_id IS NULL AS kn, COALESCE(ca.browser_id, 0) AS k,
                    COUNT(*) AS n, SUM(jm.touches = 1) AS one_touch
             FROM 202_attribution_journey_meta jm
             JOIN 202_attribution_journeys j ON j.conv_id = jm.conv_id AND j.position + 1 = jm.touches
             LEFT JOIN 202_clicks_advance ca ON ca.click_id = j.click_id
             WHERE jm.user_id = ? AND jm.conv_time >= ? AND jm.conv_time <= ?
             GROUP BY b, kn, k'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $from, $to]);
        foreach ($this->conn->fetchAll($stmt) as $r) {
            $b = (int) $r['b'];
            $kn = (int) $r['kn'];
            $rows[] = $row(self::JOURNEY_BROWSER, $b, $kn, (string) $r['k'], (int) $r['n']);
            if ((int) $r['one_touch'] > 0) {
                $rows[] = $row(self::JOURNEY_BROWSER_ONE_TOUCH, $b, $kn, (string) $r['k'], (int) $r['one_touch']);
            }
        }

        return $rows;
    }

    /**
     * A UTC day's rows, summed from its hours. The day dimension has none:
     * a report groups it by the local date of each hour. A key's NULL and
     * its value stay apart as they are in the hours (the journey browsers
     * need that; a breakdown sums both into one group either way).
     */
    public function buildDay(int $userId, int $day): void
    {
        $del = $this->conn->prepareWrite(
            'DELETE FROM 202_attribution_rollup WHERE user_id = ? AND grain = ? AND bucket = ?'
        );
        $this->conn->bind($del, 'iii', [$userId, self::GRAIN_DAY, $day]);
        $this->conn->executeUpdate($del);

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_attribution_rollup
                (user_id, part, dim, model_id, grain, bucket, key_null, dim_key, n, credit, revenue, cost)
             SELECT user_id, part, dim, model_id, ?, ?, key_null, dim_key, SUM(n), SUM(credit), SUM(revenue), SUM(cost)
             FROM 202_attribution_rollup
             WHERE user_id = ? AND grain = ? AND bucket BETWEEN ? AND ? AND dim <> ?
             GROUP BY user_id, part, dim, model_id, key_null, dim_key'
        );
        $this->conn->bind($stmt, 'iiiiiii', [self::GRAIN_DAY, $day, $userId, self::GRAIN_HOUR, $day * 24, $day * 24 + 23, self::DIMENSION_CODES['day']]);
        $this->conn->executeUpdate($stmt);
    }

    /**
     * The first hour at or after $fromHour with anything to sum: a click, a
     * conversion's journey (credits never exist without one), or rollup
     * rows that may now have to go.
     */
    public function nextActiveHour(int $userId, int $fromHour): ?int
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT (SELECT MIN(click_time) FROM 202_clicks WHERE user_id = ? AND click_time >= ?) AS c,
                    (SELECT MIN(conv_time) FROM 202_attribution_journey_meta WHERE user_id = ? AND conv_time >= ?) AS j,
                    (SELECT MIN(bucket) FROM 202_attribution_rollup WHERE user_id = ? AND grain = ? AND bucket >= ?) AS r'
        );
        $at = $fromHour * 3600;
        $this->conn->bind($stmt, 'iiiiiii', [$userId, $at, $userId, $at, $userId, self::GRAIN_HOUR, $fromHour]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new \RuntimeException('the rollup could not read the next active hour');
        }
        $candidates = [];
        foreach (['c', 'j'] as $k) {
            if ($row[$k] !== null) {
                $candidates[] = intdiv((int) $row[$k], 3600);
            }
        }
        if ($row['r'] !== null) {
            $candidates[] = (int) $row['r'];
        }

        return $candidates === [] ? null : min($candidates);
    }

    /**
     * The dimension key expression without the name join: the name is looked
     * up when a report runs. Every name table is joined on its primary key
     * (a LEFT JOIN that can neither add nor drop a row), so leaving it out
     * changes no sum.
     *
     * @return array{0: string, 1: string}
     */
    public static function keySql(string $dimension, string $timeColumn): array
    {
        if ($dimension === 'day') {
            // One key per hour: the report turns the hour into a local date.
            return ['0', ''];
        }
        [$key, , $joins] = AttributionReports::dimensionSql($dimension, $timeColumn);
        $kept = array_filter(
            explode("\n", $joins),
            static fn (string $j): bool => !str_contains($j, ' dn ON ')
        );

        return [$key, implode("\n", $kept)];
    }

    /**
     * The dimension key over rows (not hours): keySql() with the day
     * dimension's real key, the local date of $timeColumn.
     *
     * @return array{0: string, 1: null, 2: string}
     */
    public static function keySqlWithTime(string $dimension, string $timeColumn): array
    {
        if ($dimension === 'day') {
            [$key] = AttributionReports::dimensionSql($dimension, $timeColumn);

            return [$key, null, ''];
        }
        [$key, $joins] = self::keySql($dimension, $timeColumn);

        return [$key, null, $joins];
    }

    /**
     * The effective credit rows where $timePredicate holds: each conversion
     * under its campaign's override when that is an active model of the
     * account, else the default. The same predicate as AttributionReports'
     * full computation, narrowed by the model ids it can resolve to — every
     * model of the account, and the default — so the (model_id, conv_time)
     * index serves it. Values are inlined (all integers).
     *
     * @param list<int> $modelIds every model of the account, and the default
     * @return array{0: string, 1: string}
     */
    public static function effectiveSource(int $userId, int $defaultModelId, array $modelIds, string $timePredicate): array
    {
        return [
            "202_attribution_credits cr
             JOIN 202_conversion_logs cl ON cl.conv_id = cr.conv_id
             LEFT JOIN 202_aff_campaigns oc ON oc.aff_campaign_id = cl.campaign_id
             LEFT JOIN 202_attribution_models om
                ON om.model_id = oc.attribution_model_id AND om.user_id = cl.user_id AND om.status = 'active'",
            'cr.model_id IN (' . self::intList($modelIds) . ') AND ' . $timePredicate
                . ' AND cl.user_id = ' . $userId . ' AND cr.model_id = COALESCE(om.model_id, ' . $defaultModelId . ')',
        ];
    }

    /**
     * The live overrides: every campaign whose attribution_model_id names an
     * active model of the account — exactly the campaigns whose conversions
     * the effective read credits to a model other than the default.
     */
    public const CURRENT_OVERRIDES_SQL =
        "SELECT oc.aff_campaign_id AS campaign_id, om.model_id AS model_id
         FROM 202_attribution_models om
         JOIN 202_aff_campaigns oc ON oc.attribution_model_id = om.model_id
         WHERE om.user_id = %d AND om.status = 'active'";

    /**
     * 1 when the stored overrides are exactly the live ones. Both sets are
     * keyed by campaign (a campaign has one override), so equal counts and a
     * join that matches every stored row make them the same set.
     */
    public static function overridesMatchSql(int $userId): string
    {
        $current = sprintf(self::CURRENT_OVERRIDES_SQL, $userId);

        return sprintf(
            '((SELECT COUNT(*) FROM (%1$s) cur) = (SELECT COUNT(*) FROM 202_attribution_rollup_overrides WHERE user_id = %2$d)
              AND (SELECT COUNT(*) FROM (%1$s) cur JOIN 202_attribution_rollup_overrides o
                     ON o.user_id = %2$d AND o.campaign_id = cur.campaign_id AND o.model_id = cur.model_id)
                  = (SELECT COUNT(*) FROM 202_attribution_rollup_overrides WHERE user_id = %2$d))',
            $current,
            $userId
        );
    }

    /**
     * 1 when every instant of hour $hourExpr falls on one local date in the
     * session's time zone: the date at its first and last second agree and
     * the UTC offset did not change inside it (TO_SECONDS of the local
     * wall-clock time minus the instant is the offset plus a constant). With
     * one offset for the whole hour the wall clock runs forward without a
     * jump, so two equal end dates mean one date throughout.
     */
    public static function hourIsOneLocalDateSql(string $hourExpr): string
    {
        $start = "($hourExpr) * 3600";
        $end = "($hourExpr) * 3600 + 3599";

        return "(DATE(FROM_UNIXTIME($start)) = DATE(FROM_UNIXTIME($end))
                 AND TO_SECONDS(FROM_UNIXTIME($start)) - $start = TO_SECONDS(FROM_UNIXTIME($end)) - ($end))";
    }

    /**
     * 1 when the account's rollup carries the journey part (GRAIN_MARKER):
     * every built hour was summed with it, or is marked dirty.
     */
    public static function journeysMarkerSql(int $userId): string
    {
        return sprintf(
            'EXISTS (SELECT 1 FROM 202_attribution_rollup jmk WHERE jmk.user_id = %d AND jmk.part = %d AND jmk.dim = 0 AND jmk.model_id = %d AND jmk.grain = %d)',
            $userId,
            self::PART_JOURNEYS,
            self::EFFECTIVE,
            self::GRAIN_MARKER
        );
    }

    private function journeysKept(int $userId): bool
    {
        $stmt = $this->conn->prepareWrite('SELECT ' . self::journeysMarkerSql($userId) . ' AS kept');
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new \RuntimeException('the rollup could not read whether account ' . $userId . ' keeps the journey part');
        }

        return (int) $row['kept'] === 1;
    }

    private function markJourneysKept(int $userId): void
    {
        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_attribution_rollup (user_id, part, dim, model_id, grain, bucket, key_null, dim_key, n, credit, revenue, cost)
             VALUES (?, ?, 0, ?, ?, 0, 0, 0, 1, 0, 0, 0) ON DUPLICATE KEY UPDATE n = 1'
        );
        $this->conn->bind($stmt, 'iiii', [$userId, self::PART_JOURNEYS, self::EFFECTIVE, self::GRAIN_MARKER]);
        $this->conn->executeUpdate($stmt);
    }

    /** @param list<int> $ids */
    public static function intList(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $ids === [] ? 'NULL' : implode(',', $ids);
    }

    /** @return array{built: int, default: int|null}|null */
    private function state(int $userId): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT built_through_hour, default_model_id FROM 202_attribution_rollup_state WHERE user_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : [
            'built' => (int) $row['built_through_hour'],
            'default' => $row['default_model_id'] !== null ? (int) $row['default_model_id'] : null,
        ];
    }

    /**
     * The default the effective rows are summed under, read inside the
     * build's transaction: null when the account has no default (a report
     * never reads effective rows for it then). When the stored overrides are
     * no longer the live ones, sync() has not seen the change yet; summing
     * now would leave hours without effective rows that a later change back
     * could make look clean, so the build is refused and the account waits
     * for the next run's sync.
     */
    private function buildDefault(int $userId): ?int
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT s.default_model_id, ' . self::overridesMatchSql($userId) . ' AS map_ok
             FROM 202_attribution_rollup_state s WHERE s.user_id = ?'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            throw new RollupOutOfSync('account ' . $userId . ' has no rollup state');
        }
        if ((int) $row['map_ok'] !== 1) {
            throw new RollupOutOfSync('account ' . $userId . "'s campaign model overrides changed since the last sync");
        }

        return $row['default_model_id'] !== null ? (int) $row['default_model_id'] : null;
    }

    /** @return list<array{0: int, 1: int}> campaign_id, model_id — sorted */
    private function currentOverrides(int $userId): array
    {
        $stmt = $this->conn->prepareWrite(sprintf(self::CURRENT_OVERRIDES_SQL, $userId) . ' ORDER BY campaign_id');

        return array_map(static fn (array $r): array => [(int) $r['campaign_id'], (int) $r['model_id']], $this->conn->fetchAll($stmt));
    }

    /** @return list<array{0: int, 1: int}> campaign_id, model_id — sorted */
    private function storedOverrides(int $userId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT campaign_id, model_id FROM 202_attribution_rollup_overrides WHERE user_id = ? ORDER BY campaign_id'
        );
        $this->conn->bind($stmt, 'i', [$userId]);

        return array_map(static fn (array $r): array => [(int) $r['campaign_id'], (int) $r['model_id']], $this->conn->fetchAll($stmt));
    }

    private function currentDefault(int $userId): ?int
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT model_id FROM 202_attribution_models WHERE user_id = ? AND is_default = 1 LIMIT 1'
        );
        $this->conn->bind($stmt, 'i', [$userId]);
        $row = $this->conn->fetchOne($stmt);

        return $row === null ? null : (int) $row['model_id'];
    }

    /** @param list<array{0: int, 1: int}> $overrides */
    private function storeOverrides(int $userId, array $overrides): void
    {
        $del = $this->conn->prepareWrite('DELETE FROM 202_attribution_rollup_overrides WHERE user_id = ?');
        $this->conn->bind($del, 'i', [$userId]);
        $this->conn->executeUpdate($del);
        foreach (array_chunk($overrides, self::INSERT_CHUNK) as $chunk) {
            $values = [];
            foreach ($chunk as [$campaignId, $modelId]) {
                array_push($values, $userId, $campaignId, $modelId);
            }
            $ins = $this->conn->prepareWrite(
                'INSERT INTO 202_attribution_rollup_overrides (user_id, campaign_id, model_id) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'))
            );
            $this->conn->bind($ins, str_repeat('iii', count($chunk)), $values);
            $this->conn->executeUpdate($ins);
        }
    }

    /**
     * The dirty hours of $rows inside [$from, $to], merged into ranges.
     *
     * @param list<array{id: int, from: int, to: int}> $rows
     * @return list<array{0: int, 1: int}>
     */
    private function dirtyHoursWithin(array $rows, int $from, int $to): array
    {
        $ranges = [];
        foreach ($rows as $r) {
            $a = max($r['from'], $from);
            $b = min($r['to'], $to);
            if ($a <= $b) {
                $ranges[] = [$a, $b];
            }
        }
        usort($ranges, static fn (array $x, array $y): int => $x[0] <=> $y[0]);
        $merged = [];
        foreach ($ranges as [$a, $b]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $a <= $merged[$last][1] + 1) {
                $merged[$last][1] = max($merged[$last][1], $b);
            } else {
                $merged[] = [$a, $b];
            }
        }

        return $merged;
    }

    /**
     * Every hour up to $through has been re-summed: delete the rows that
     * end there (a row's hours at or above the built frontier mean
     * nothing: the build reads them when it gets there), and move the
     * start of the rest past it.
     *
     * @param list<array{id: int, from: int, to: int}> $rows
     * @return list<array{id: int, from: int, to: int}> the rows still open
     */
    private function consume(array $rows, int $through, int $built): array
    {
        $done = [];
        $open = [];
        foreach ($rows as $r) {
            if (min($r['to'], $built - 1) <= $through) {
                $done[] = $r['id'];
            } elseif ($r['from'] <= $through) {
                $upd = $this->conn->prepareWrite('UPDATE 202_attribution_rollup_dirty SET hour_from = ? WHERE dirty_id = ?');
                $this->conn->bind($upd, 'ii', [$through + 1, $r['id']]);
                $this->conn->executeUpdate($upd);
                $open[] = ['id' => $r['id'], 'from' => $through + 1, 'to' => $r['to']];
            } else {
                $open[] = $r;
            }
        }
        if ($done !== []) {
            $this->deleteDirty($done);
        }

        return $open;
    }

    /** @param list<int> $ids */
    private function deleteDirty(array $ids): void
    {
        foreach (array_chunk($ids, self::INSERT_CHUNK) as $chunk) {
            $del = $this->conn->prepareWrite('DELETE FROM 202_attribution_rollup_dirty WHERE dirty_id IN (' . self::intList($chunk) . ')');
            $this->conn->executeUpdate($del);
        }
    }
}
