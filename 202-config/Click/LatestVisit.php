<?php

declare(strict_types=1);

namespace Prosper202\Click;

use InvalidArgumentException;
use Prosper202\Database\Connection;

/**
 * A redirector re-click gives the click another 202_clicks row, at its own
 * visit's time; the click as it is now is its latest row, the one its route,
 * redirect URL and cloaked link are. A read of one click's row that takes no
 * order gets whichever row the database reads first, usually the first visit.
 *
 * Two visits in one second share a time, and the table has no per-visit key
 * to order them by. The one on the route the click holds now
 * (202_clicks_rotator, written with the latest visit) is taken first, which
 * tells them apart only while their routes differ: two visits on one route,
 * or any after an exit (MysqlClickRepository::recordExit() writes the exit's
 * route on every row of the click), are read in whatever order the database
 * gives.
 */
final class LatestVisit
{
    /**
     * The ORDER BY that puts a click's latest visit first: $clicks is the
     * 202_clicks alias, $route the 202_clicks_rotator one, joined on the
     * click (a LEFT JOIN: a click off the redirector has no route row).
     */
    public static function orderBy(string $clicks, string $route): string
    {
        self::alias($clicks);
        self::alias($route);

        return "ORDER BY {$clicks}.click_time DESC,"
            . " ({$clicks}.rotator_id = {$route}.rotator_id AND {$clicks}.rule_id = {$route}.rule_redirect_id) DESC";
    }

    /**
     * The condition that the 202_clicks row $clicks is its click's latest
     * visit, for a statement that reads many clicks' rows (a report, a
     * rollup): no visit of the click is later, as 202_click_latest_visits
     * records it. A click then counts once, under its latest visit, except
     * that two visits in one second are both latest: the table has no
     * per-visit key to tell them apart. A rollup keyed by click and time
     * folds them into one row.
     *
     * The record is read by its primary key. A probe of 202_clicks itself
     * reads its click_id index in every partition (installs partition it by
     * week), and a 30-day report went from 1 s to 80 s that way. A click
     * with no record has one visit, or is a re-click the 1.9.78 backfill has
     * not reached yet, and then counts at each visit, as it did before.
     */
    public static function isLatest(string $clicks): string
    {
        self::alias($clicks);
        if (strtolower($clicks) === 'lv_later') {
            throw new InvalidArgumentException('lv_later is the alias isLatest() reads the later visits by');
        }

        return 'NOT EXISTS (SELECT 1 FROM 202_click_latest_visits AS lv_later'
            . " WHERE lv_later.click_id = {$clicks}.click_id AND lv_later.click_time > {$clicks}.click_time)";
    }

    /**
     * Record the click's latest visit time, the latest of its 202_clicks
     * rows (not the visit just written: a web server's clock can put a
     * re-click before the visit it follows). Run by whatever adds a visit,
     * under the click's lock, after the row is written. The rows are read
     * plainly: a read inside INSERT ... SELECT would lock them, and the gap
     * after the newest click's rows is where every new click is inserted.
     */
    public static function refresh(Connection $conn, int $clickId): void
    {
        // The write handle: the caller's transaction holds the row it just wrote.
        $stmt = $conn->prepareWrite('SELECT MAX(click_time) AS latest FROM 202_clicks WHERE click_id = ?');
        $conn->bind($stmt, 'i', [$clickId]);
        $latest = $conn->fetchOne($stmt)['latest'] ?? null;
        if ($latest === null) {
            throw new \UnexpectedValueException("Click {$clickId} has no visit to record");
        }
        $stmt = $conn->prepareWrite(
            'INSERT INTO 202_click_latest_visits (click_id, click_time) VALUES (?, ?)'
                . ' ON DUPLICATE KEY UPDATE click_time = GREATEST(click_time, VALUES(click_time))'
        );
        $conn->bind($stmt, 'ii', [$clickId, (int) $latest]);
        $conn->execute($stmt);
    }

    /**
     * A derived table holding one click's latest 202_clicks row, to stand
     * where the statement read `202_clicks`: $clickId is `?` (bound) or the
     * click id as digits.
     */
    public static function of(string $clickId): string
    {
        if ($clickId !== '?' && preg_match('/\A[1-9][0-9]{0,19}\z/', $clickId) !== 1) {
            throw new InvalidArgumentException('A click id is ? or its digits');
        }

        return '(SELECT lv.* FROM 202_clicks AS lv'
            . ' LEFT JOIN 202_clicks_rotator AS lvr ON lvr.click_id = lv.click_id'
            . " WHERE lv.click_id = {$clickId} " . self::orderBy('lv', 'lvr') . ' LIMIT 1)';
    }

    private static function alias(string $alias): void
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/', $alias) !== 1) {
            throw new InvalidArgumentException('Not a table alias: ' . $alias);
        }
    }
}
