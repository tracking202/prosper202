<?php

declare(strict_types=1);

namespace Prosper202\Report;

use Prosper202\Database\Connection;

/**
 * The marks every writer of what the attribution report rollup sums leaves
 * behind (Prosper202\Attribution\AttributionRollup, rule 2). Each is
 * written through the writer's own connection inside the same transaction as
 * the change it marks, so no report can see the change without the mark.
 *
 * It lives outside the attribution engine's namespace on purpose: the click
 * and conversion paths write these marks the way they write the outbox — a
 * row in the same transaction — and never call into the engine
 * (tests/Attribution/ConversionPathsDoNotCallTheEngineTest).
 *
 * Who calls what, and why the rest need not, is held by
 * tests/Attribution/RollupWritersAreMarkedTest.
 */
final class RollupDirty
{
    /**
     * A redirect that rewrites an existing click may skip the mark when
     * every row of that click is younger than this: its own hour is not
     * summed (an hour is summed once AttributionRollup::SEAL_SECONDS have
     * passed since it ended), and AttributionRollup never leaves an hour
     * clean while a journey in it holds a click that young. The hour between
     * the two is the room a writer's transaction has to commit in.
     */
    public const HOT_PATH_SECONDS = 3600;

    /**
     * Whether a redirect rewriting a click whose oldest row is from
     * $firstClickTime must mark it. A time it could not read is marked.
     */
    public static function hotPathRewriteNeedsMark(mixed $firstClickTime, ?int $now = null): bool
    {
        if (!is_int($firstClickTime) && !(is_string($firstClickTime) && preg_match('/^\d{1,10}$/D', $firstClickTime) === 1)) {
            return true;
        }

        return (int) $firstClickTime < ($now ?? time()) - self::HOT_PATH_SECONDS;
    }

    /** Hours [$hourFrom, $hourTo] (unix time DIV 3600) of an account changed. */
    public static function hours(Connection $conn, int $userId, int $hourFrom, int $hourTo): void
    {
        if ($hourTo < $hourFrom) {
            throw new \InvalidArgumentException('hour range ends before it starts');
        }
        $stmt = $conn->prepareWrite('INSERT INTO 202_attribution_rollup_dirty (user_id, hour_from, hour_to) VALUES (?, ?, ?)');
        $conn->bind($stmt, 'iii', [$userId, max(0, $hourFrom), max(0, $hourTo)]);
        $conn->executeUpdate($stmt);
    }

    /** Clicks of an account timed between $fromTime and $toTime (unix seconds) changed. */
    public static function timeRange(Connection $conn, int $userId, int $fromTime, int $toTime): void
    {
        self::hours($conn, $userId, intdiv(max(0, $fromTime), 3600), intdiv(max(0, $toTime), 3600));
    }

    /** Rows one multi-row INSERT of hourRuns() writes at most. */
    private const RUN_CHUNK = 500;

    /**
     * Any set of an account's hours changed: one mark per run of
     * consecutive hours, written in multi-row INSERTs — a bulk change marks
     * as many rows as it has stretches of time, never one per row it
     * touched. hours() for each run would mark the same hours.
     *
     * @param array<int, list<int>> $hoursByUser user_id => hours (unix time DIV 3600), any order, repeats allowed
     * @return int marks written
     */
    public static function hourRuns(Connection $conn, array $hoursByUser): int
    {
        $values = [];
        foreach ($hoursByUser as $userId => $hours) {
            $hours = array_values(array_unique(array_map(static fn (int $h): int => max(0, $h), $hours)));
            sort($hours);
            $start = null;
            $prev = null;
            foreach ($hours as $h) {
                if ($start !== null && $h === $prev + 1) {
                    $prev = $h;
                    continue;
                }
                if ($start !== null) {
                    array_push($values, (int) $userId, $start, $prev);
                }
                $start = $h;
                $prev = $h;
            }
            if ($start !== null) {
                array_push($values, (int) $userId, $start, $prev);
            }
        }
        foreach (array_chunk($values, self::RUN_CHUNK * 3) as $chunk) {
            $rows = intdiv(count($chunk), 3);
            $stmt = $conn->prepareWrite(
                'INSERT INTO 202_attribution_rollup_dirty (user_id, hour_from, hour_to) VALUES '
                . implode(', ', array_fill(0, $rows, '(?, ?, ?)'))
            );
            $conn->bind($stmt, str_repeat('iii', $rows), $chunk);
            $conn->executeUpdate($stmt);
        }

        return intdiv(count($values), 3);
    }

    /**
     * Clicks are about to be deleted, with every row keyed by them
     * (click-data retention, Prosper202\Click\ClickRetention): mark what
     * the rollup summed them into, read here, before the rows go, in the
     * transaction that deletes them —
     *
     *  - the hours of the clicks' own rows (the cost part, and every
     *    dimension key their clicks_advance and clicks_tracking rows give);
     *  - the conversion hours of every journey that holds one (assists join
     *    the click; the journey part's browser joins its clicks_advance row);
     *  - the conversion hours of every credit that names one, under the
     *    models of the accounts the click rows belong to (the credit part
     *    joins the click's row, so a click with none counts in no credit
     *    sum). Read from the credits themselves, not taken to be the
     *    journeys' touches: a journey that lost a position still has its
     *    credit, and RollupMatchesFullComputationTest's tenants carry
     *    exactly that.
     *
     * Only accounts with an attribution model are marked: AttributionRollup
     * sums no other (accounts()), and DefaultModel gives every account one
     * from the moment it exists, so an account without one is a deleted
     * user — whose rollup UserDataPurge removed, and whose clicks are kept
     * and age out like any other. A mark there would never be consumed.
     *
     * @param list<int> $clickIds
     * @return int marks written
     */
    public static function clicksDeleted(Connection $conn, array $clickIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $clickIds)));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $hours = [];
        $stmt = $conn->prepareWrite("SELECT DISTINCT user_id, click_time DIV 3600 AS h FROM 202_clicks WHERE click_id IN ($in)");
        $conn->bind($stmt, $types, $ids);
        foreach ($conn->fetchAll($stmt) as $r) {
            $hours[(int) $r['user_id']][] = (int) $r['h'];
        }
        // The accounts whose click rows these are: only their credits can
        // count these clicks (the credit part joins the click's row, and an
        // account's journeys, so its credits, hold its own clicks).
        $clickAccounts = array_keys($hours);
        $stmt = $conn->prepareWrite(
            "SELECT DISTINCT jm.user_id, jm.conv_time DIV 3600 AS h
             FROM 202_attribution_journeys j JOIN 202_attribution_journey_meta jm ON jm.conv_id = j.conv_id
             WHERE j.click_id IN ($in)"
        );
        $conn->bind($stmt, $types, $ids);
        foreach ($conn->fetchAll($stmt) as $r) {
            $hours[(int) $r['user_id']][] = (int) $r['h'];
        }
        if ($hours === []) {
            return 0;
        }

        $users = array_keys($hours);
        $stmt = $conn->prepareWrite(
            'SELECT model_id, user_id FROM 202_attribution_models WHERE user_id IN (' . implode(',', array_fill(0, count($users), '?')) . ')'
        );
        $conn->bind($stmt, str_repeat('i', count($users)), $users);
        $modelled = [];
        foreach ($conn->fetchAll($stmt) as $model) {
            $userId = (int) $model['user_id'];
            $modelled[$userId] = true;
            if (!in_array($userId, $clickAccounts, true)) {
                continue;
            }
            // One model at a time: with the model a constant, (model_id,
            // click_id) serves the read; joined to the models, MariaDB read
            // every credit of each model instead.
            $credits = $conn->prepareWrite(
                "SELECT DISTINCT conv_time DIV 3600 AS h FROM 202_attribution_credits WHERE model_id = ? AND click_id IN ($in)"
            );
            $conn->bind($credits, 'i' . $types, array_merge([(int) $model['model_id']], $ids));
            foreach ($conn->fetchAll($credits) as $r) {
                $hours[$userId][] = (int) $r['h'];
            }
        }

        return self::hourRuns($conn, array_intersect_key($hours, $modelled));
    }

    /**
     * The cost of one existing click changed, and nothing else about it:
     * only the hours its own rows sit in sum it (credits and assists never
     * read click_cpc), so those are marked directly, from the rows as the
     * transaction sees them.
     */
    public static function clickCost(Connection $conn, int $clickId): void
    {
        $stmt = $conn->prepareWrite(
            'INSERT INTO 202_attribution_rollup_dirty (user_id, hour_from, hour_to)
             SELECT DISTINCT user_id, click_time DIV 3600, click_time DIV 3600 FROM 202_clicks WHERE click_id = ?'
        );
        $conn->bind($stmt, 'i', [$clickId]);
        $conn->executeUpdate($stmt);
    }

    /**
     * click(), for a writer that does not know the click's account: it is
     * read from the click's rows.
     */
    public static function clickOfAnyAccount(Connection $conn, int $clickId): void
    {
        $stmt = $conn->prepareWrite(
            'INSERT INTO 202_attribution_rollup_dirty_clicks (user_id, click_id)
             SELECT DISTINCT user_id, click_id FROM 202_clicks WHERE click_id = ?'
        );
        $conn->bind($stmt, 'i', [$clickId]);
        $conn->executeUpdate($stmt);
    }

    /**
     * One existing click was rewritten (a rotator re-click replaces its row,
     * its keyword and its c1–c4; a click leaving its landing page takes a
     * campaign; a tracking row is added to a click that had none). Its hours
     * and those of the conversions whose journeys hold it are resolved by
     * the rollup; until then the account's reports are computed in full.
     */
    public static function click(Connection $conn, int $userId, int $clickId): void
    {
        $stmt = $conn->prepareWrite('INSERT INTO 202_attribution_rollup_dirty_clicks (user_id, click_id) VALUES (?, ?)');
        $conn->bind($stmt, 'ii', [$userId, $clickId]);
        $conn->executeUpdate($stmt);
    }

    /**
     * A conversion's journey or credits are about to be rewritten or
     * removed: mark the hours its stored rows sit in (read before they
     * change), and the hour of $newConvTime when it gets new ones.
     */
    public static function conversion(Connection $conn, int $convId, ?int $userId = null, ?int $newConvTime = null): void
    {
        $stmt = $conn->prepareWrite(
            'SELECT user_id, conv_time DIV 3600 AS h FROM 202_attribution_journey_meta WHERE conv_id = ?
             UNION
             SELECT m.user_id, cr.conv_time DIV 3600 AS h
             FROM 202_attribution_credits cr JOIN 202_attribution_models m ON m.model_id = cr.model_id
             WHERE cr.conv_id = ?'
        );
        $conn->bind($stmt, 'ii', [$convId, $convId]);
        $marks = [];
        foreach ($conn->fetchAll($stmt) as $r) {
            $marks[(int) $r['user_id'] . ':' . (int) $r['h']] = [(int) $r['user_id'], (int) $r['h']];
        }
        if ($userId !== null && $newConvTime !== null) {
            $h = intdiv($newConvTime, 3600);
            $marks[$userId . ':' . $h] = [$userId, $h];
        }
        foreach ($marks as [$u, $h]) {
            self::hours($conn, $u, $h, $h);
        }
    }
}
