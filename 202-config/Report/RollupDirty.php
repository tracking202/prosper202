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
