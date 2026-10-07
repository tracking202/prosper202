<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;
use Prosper202\Report\RollupDirty;

/**
 * Click-data retention: the one routine that deletes clicks, behind both of
 * the cron job's deletions (202-cronjobs/index.php), which read their
 * settings from user 1's preferences and apply to every account's clicks:
 *
 *  - **automatic** (`user_auto_database_optimization_days`; the page's
 *    "Delete click data older than", PUT /system/retention): every click all
 *    of whose rows are older than midnight that began the day that many days
 *    before today (cutoff());
 *  - **scheduled** (`user_delete_data_before`; the page's Advanced ›
 *    "Delete click data from before", POST /system/retention/delete-before):
 *    every click all of whose rows are older than the stored time, midnight
 *    that began the chosen day in the scheduler's zone — the same rule as the
 *    automatic deletion, by the same query (expiredClicks()), and the one the
 *    API's preview counts by (countOlderThan()).
 *
 * The scheduled deletion used to store an id instead: MAX(click_id) of the
 * clicks recorded at or before the day, in `user_delete_data_clickid`, and
 * delete every click below it. An id range is not a time range (below), so
 * it deleted a click re-clicked after the day and kept the newest click from
 * before it (measured live: a deletion "from before Oct 7" deleted a click
 * whose second row was recorded on Oct 7, and kept one from Oct 4). Nothing
 * writes the id any more; an install that scheduled one before still has it
 * honoured as it was promised (deleteBelow()), because the day it was made
 * from was never stored and cannot be recovered from it. A stored time wins
 * over an id beside it: every writer of the time clears the id in the same
 * statement, so the two together are only ever a hand edit, and the time is
 * the schedule the current page or API made.
 *
 * Both select click ids and hand them to deleteClicks(), which deletes a
 * batch from every table in TABLES in one transaction — so no table keeps a
 * row of a click another table has lost — and marks the attribution report
 * rollup's hours in the same transaction (RollupDirty::clicksDeleted();
 * AttributionRollup rule 2), so the rollup re-sums them from what remains,
 * as 202_dataengine, deleted in the same batch, already reads.
 *
 * Why a deletion by date selects the expired clicks' ids rather than an id
 * boundary: click_id is not ordered by click_time. A redirect takes
 * click_time before it allocates the id (dl.php reads time() some two
 * hundred lines earlier, with geo and filter lookups between), so concurrent
 * requests interleave; and a rotator re-click (rtr.php, ?lpr=) gives an
 * existing click another 202_clicks row at the time of the re-click. The
 * automatic job this replaced took MIN(click_id) of the expired clicks and
 * deleted the ids below it — below the oldest expired click, so in practice
 * nothing (CLAUDE.md #26).
 *
 * A click is expired when every one of its rows is: a click re-clicked
 * inside the window is kept whole, old row included, until its newest row
 * ages out, because its other tables' rows are the re-click's.
 *
 * Batching: BATCH clicks a transaction, for as long as the caller's deadline
 * allows; a backlog drains over the following runs, oldest first, each batch
 * holding row locks on its own clicks only. Every read and write goes
 * through Connection, which throws on any failure (CLAUDE.md #1): a failed
 * batch rolls back whole and the exception reaches the cron job's log.
 */
final class ClickRetention
{
    /** Clicks deleted in one transaction. */
    public const BATCH = 1000;

    /** How long each of the two deletions may run in one cron run. */
    public const RUN_SECONDS = 20;

    /** The most days automatic deletion accepts (the page's and the API's limit). */
    public const MAX_DAYS = 36500;

    /**
     * Every table that holds a click's rows, deleted with it. 202_clicks
     * goes last only for readability; the batch is one transaction.
     */
    public const TABLES = [
        '202_clicks_advance',
        '202_clicks_record',
        '202_clicks_site',
        '202_clicks_spy',
        '202_clicks_tracking',
        '202_clicks_variable',
        '202_clicks_rotator',
        '202_cpa_trackers',
        '202_google',
        '202_bing',
        '202_facebook',
        '202_dataengine',
        // The identity graph's per-click rows: the signals a click carried
        // and the visitor key it was given. UserDataPurge can reach
        // observations only through the click's 202_clicks row, so left
        // behind they would outlive a later deletion of their account.
        '202_identity_observations',
        '202_clicks_visitor',
        '202_clicks',
    ];

    /**
     * Every other table with a column that holds a click id, and why its rows
     * stay when the click goes. ClickRetentionCoversEveryClickTableTest holds
     * TABLES and this list to the schema: a new click-keyed table fails it
     * until it is placed in one of them.
     */
    public const KEPT = [
        '202_clicks_counter' => 'the click id allocator: an emptied AUTO_INCREMENT table restarts at 1 on MySQL 5.7 and MariaDB before 10.2.4 after a restart, and new clicks would reuse the ids conversions still name',
        '202_conversion_logs' => 'conversions: the money, and the ledger that counts it, outlive the click they came through',
        '202_attribution_journeys' => 'a conversion\'s journey, kept with the conversion; the rollup and the reports join the click and drop a touch whose click is gone',
        '202_attribution_credits' => 'a conversion\'s credits, kept with the conversion: its totals stay whole; breakdowns join the click',
        '202_attribution_rollup_dirty_clicks' => 'rollup marks: resolving a click that is gone marks the hours of the journeys that still hold it',
        '202_attribution_backfill' => 'next_click_id and through_click_id are the ledger backfill\'s cursor, not a click\'s row',
        '202_identity_merges' => 'a merge of two visitors, with the click that proved it: the visitors stay merged, and the row goes with its account',
        '202_app_installs' => 'app installs, pruned by AppRetention on their own windows',
        '202_revenue_events' => 'the LTV revenue ledger: money, kept with the customer',
        '202_engagement_events' => 'LTV engagement events, kept with the customer',
        '202_personalization_tokens' => 'personalization tokens, purged on their own replay window',
        '202_customers' => 'first_click_id: the customer\'s first click, kept as the customer\'s acquisition record',
        '202_users_pref' => 'user_delete_data_clickid is the scheduled deletion\'s marker (an earlier version\'s form)',
    ];

    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(private Connection $conn, private int $batch = self::BATCH, ?callable $clock = null)
    {
        if ($batch < 1) {
            throw new \InvalidArgumentException('a retention batch holds at least one click');
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The automatic deletion's cutoff: midnight that began the day $days days
     * before $now's, in PHP's time zone (the cron job's, as it always was).
     * Clicks before it are deleted; $days whole days and today are kept.
     */
    public static function cutoff(int $days, int $now): int
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new \InvalidArgumentException('automatic deletion keeps 1 to ' . self::MAX_DAYS . ' days, not ' . $days);
        }

        return (new \DateTimeImmutable('@' . $now))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->setTime(0, 0)
            ->modify('-' . $days . ' days')
            ->getTimestamp();
    }

    /**
     * Run the automatic deletion user 1's preferences ask for, until
     * $deadline. Off (0, or no preferences row) deletes nothing.
     *
     * @return array{days: int, cutoff: int|null, clicks: int, rows: array<string, int>, batches: int, complete: bool}
     */
    public function runAutomatic(int $deadline): array
    {
        $prefs = $this->ownerPrefs();
        $days = self::days($prefs['user_auto_database_optimization_days'] ?? null);
        if ($days === 0) {
            return ['days' => 0, 'cutoff' => null] + self::emptyReport();
        }
        $cutoff = self::cutoff($days, ($this->clock)());

        return ['days' => $days, 'cutoff' => $cutoff] + $this->deleteOlderThan($cutoff, $deadline);
    }

    /**
     * Run the one-off deletion scheduled on user 1's preferences, until
     * $deadline: by the stored time when there is one, else by an id marker
     * an earlier version stored. None scheduled deletes nothing.
     *
     * @return array{before: int|null, marker: int|null, clicks: int, rows: array<string, int>, batches: int,
     *     complete: bool}
     */
    public function runScheduled(int $deadline): array
    {
        ['before' => $before, 'marker' => $marker] = self::scheduled($this->ownerPrefs());
        if ($before !== null) {
            return ['before' => $before, 'marker' => null] + $this->deleteOlderThan($before, $deadline);
        }
        if ($marker !== null) {
            return ['before' => null, 'marker' => $marker] + $this->deleteBelow($marker, $deadline);
        }

        return ['before' => null, 'marker' => null] + self::emptyReport();
    }

    /**
     * What user 1's preferences schedule: the time (user_delete_data_before)
     * when one is stored — an id beside it is not read — else the id marker
     * an earlier version stored (user_delete_data_clickid). Unreadable is an
     * error, never "none".
     *
     * @param array<string, mixed> $prefs a 202_users_pref row
     * @return array{before: int|null, marker: int|null}
     */
    public static function scheduled(array $prefs): array
    {
        $before = self::cutoffTime($prefs['user_delete_data_before'] ?? null);
        if ($before !== null) {
            return ['before' => $before, 'marker' => null];
        }

        return ['before' => null, 'marker' => self::marker($prefs['user_delete_data_clickid'] ?? null)];
    }

    /**
     * Delete every click all of whose rows are older than $cutoff, a batch
     * at a time, each account in turn, until none is left or $deadline.
     *
     * @return array{clicks: int, rows: array<string, int>, batches: int, complete: bool}
     */
    public function deleteOlderThan(int $cutoff, int $deadline): array
    {
        $report = self::emptyReport();
        $users = $this->accountsOlderThan($cutoff);

        while ($users !== []) {
            foreach ($users as $k => $userId) {
                if (($this->clock)() >= $deadline) {
                    return $report;
                }
                $ids = $this->expiredClicks($userId, $cutoff, $this->batch);
                if ($ids === []) {
                    unset($users[$k]);
                    continue;
                }
                $report = self::add($report, $this->deleteClicks($ids), count($ids));
            }
        }
        $report['complete'] = true;

        return $report;
    }

    /**
     * What deleteOlderThan($cutoff) would delete now, counted by the same
     * selection (EXPIRED, account by account): the clicks, and their rows in
     * each of TABLES. The API's preview of a scheduled deletion. A click
     * re-clicked at or after the cutoff is in neither, and neither is a row
     * whose 202_clicks row is already gone — deleteOlderThan() reaches a
     * click through its 202_clicks rows.
     *
     * @return array{clicks: int, rows: array<string, int>}
     */
    public function countOlderThan(int $cutoff): array
    {
        $counts = ['clicks' => 0, 'rows' => array_fill_keys(self::TABLES, 0)];
        foreach ($this->accountsOlderThan($cutoff) as $userId) {
            $counts['clicks'] += $this->countExpired(self::COUNT_EXPIRED, $userId, $cutoff);
            foreach (self::TABLES as $table) {
                $counts['rows'][$table] += $this->countExpired(
                    'SELECT COUNT(*) AS n FROM `' . $table . '` AS x INNER JOIN (SELECT DISTINCT c.click_id '
                    . self::EXPIRED . ') AS e ON (e.click_id = x.click_id)',
                    $userId,
                    $cutoff
                );
            }
        }

        return $counts;
    }

    /** How many clicks deleteOlderThan($cutoff) would delete now: countOlderThan()'s clicks alone. */
    public function clicksOlderThan(int $cutoff): int
    {
        $clicks = 0;
        foreach ($this->accountsOlderThan($cutoff) as $userId) {
            $clicks += $this->countExpired(self::COUNT_EXPIRED, $userId, $cutoff);
        }

        return $clicks;
    }

    /** Whether deleteOlderThan($cutoff) would delete any click now. */
    public function anyOlderThan(int $cutoff): bool
    {
        foreach ($this->accountsOlderThan($cutoff) as $userId) {
            if ($this->expiredClicks($userId, $cutoff, 1) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete every click whose id is below $marker: the clicks first, then
     * whatever the other tables still hold below it (rows whose 202_clicks
     * row was already gone, which that version's preview counted too), until
     * nothing is left or $deadline. Only an id marker an earlier version
     * scheduled reaches this (runScheduled()); it is not a time range, which
     * is why nothing schedules one any more.
     *
     * @return array{clicks: int, rows: array<string, int>, batches: int, complete: bool}
     */
    public function deleteBelow(int $marker, int $deadline): array
    {
        $report = self::emptyReport();
        // 202_clicks last in TABLES, first here.
        foreach (array_reverse(self::TABLES) as $table) {
            while (true) {
                if (($this->clock)() >= $deadline) {
                    return $report;
                }
                $stmt = $this->conn->prepareWrite('SELECT click_id FROM `' . $table . '` WHERE click_id < ? ORDER BY click_id LIMIT ?');
                $this->conn->bind($stmt, 'ii', [$marker, $this->batch]);
                $ids = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['click_id'], $this->conn->fetchAll($stmt))));
                if ($ids === []) {
                    break;
                }
                $report = self::add($report, $this->deleteClicks($ids), $table === '202_clicks' ? count($ids) : 0);
            }
        }
        $report['complete'] = true;

        return $report;
    }

    /**
     * Delete these clicks from every table in TABLES, and mark the rollup
     * hours they were summed into, in one transaction.
     *
     * @param list<int> $clickIds
     * @return array<string, int> rows deleted per table
     */
    public function deleteClicks(array $clickIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $clickIds)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        return $this->conn->transaction(function () use ($ids, $in, $types): array {
            // Read before the rows go: the hours are the rows' own.
            RollupDirty::clicksDeleted($this->conn, $ids);
            $rows = [];
            foreach (self::TABLES as $table) {
                $stmt = $this->conn->prepareWrite('DELETE FROM `' . $table . '` WHERE click_id IN (' . $in . ')');
                $this->conn->bind($stmt, $types, $ids);
                $rows[$table] = $this->conn->executeUpdate($stmt);
            }

            return $rows;
        });
    }

    /**
     * An account's expired clicks' 202_clicks rows: a range of (user_id,
     * click_time), skipping a click that has a row at or after the cutoff.
     * The skip is a LEFT JOIN probing the click_id index row by row: written
     * as NOT EXISTS, MariaDB turned it into IN and materialized it with a
     * scan of the whole table, every batch. Binds, in order: the cutoff, the
     * account, the cutoff. The deletion and its preview both select through
     * it, so the preview cannot count by another rule.
     */
    private const EXPIRED = 'FROM 202_clicks c
             LEFT JOIN 202_clicks n ON n.click_id = c.click_id AND n.click_time >= ?
             WHERE c.user_id = ? AND c.click_time < ? AND n.click_id IS NULL';

    /** How many clicks EXPIRED selects. */
    private const COUNT_EXPIRED = 'SELECT COUNT(DISTINCT c.click_id) AS n ' . self::EXPIRED;

    /**
     * The accounts with a row before the cutoff: a loose scan of (user_id,
     * click_time), one probe an account.
     *
     * @return list<int>
     */
    private function accountsOlderThan(int $cutoff): array
    {
        $stmt = $this->conn->prepareWrite('SELECT user_id FROM 202_clicks GROUP BY user_id HAVING MIN(click_time) < ?');
        $this->conn->bind($stmt, 'i', [$cutoff]);

        return array_map(static fn (array $r): int => (int) $r['user_id'], $this->conn->fetchAll($stmt));
    }

    /**
     * Up to $limit of an account's expired clicks, oldest first.
     *
     * @return list<int>
     */
    private function expiredClicks(int $userId, int $cutoff, int $limit): array
    {
        $stmt = $this->conn->prepareWrite('SELECT c.click_id ' . self::EXPIRED . ' ORDER BY c.click_time LIMIT ?');
        $this->conn->bind($stmt, 'iiii', [$cutoff, $userId, $cutoff, $limit]);

        return array_values(array_unique(array_map(static fn (array $r): int => (int) $r['click_id'], $this->conn->fetchAll($stmt))));
    }

    /** One count over an account's expired clicks; $sql selects `n` and ends in EXPIRED's binds. */
    private function countExpired(string $sql, int $userId, int $cutoff): int
    {
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, 'iii', [$cutoff, $userId, $cutoff]);
        $row = $this->conn->fetchOne($stmt);
        if ($row === null || !array_key_exists('n', $row)) {
            throw new \UnexpectedValueException('a count of the clicks older than ' . $cutoff . ' answered no row');
        }

        return (int) $row['n'];
    }

    /** @return array<string, mixed> user 1's retention settings; [] when it has no preferences row */
    private function ownerPrefs(): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT user_auto_database_optimization_days, user_delete_data_before, user_delete_data_clickid'
            . ' FROM 202_users_pref WHERE user_id = 1 LIMIT 1'
        );

        return $this->conn->fetchOne($stmt) ?? [];
    }

    /**
     * The stored number of days, 0 for off. A value that is not a number of
     * days deletes nothing and says so: never read as some other number.
     */
    private static function days(mixed $raw): int
    {
        if ($raw === null) {
            return 0;
        }
        $s = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : '');
        if (preg_match('/^[0-9]{1,5}$/D', $s) !== 1 || (int) $s > self::MAX_DAYS) {
            throw new \UnexpectedValueException('user 1\'s user_auto_database_optimization_days is ' . var_export($raw, true)
                . ', not a number of days from 0 to ' . self::MAX_DAYS . '; nothing was deleted');
        }

        return (int) $s;
    }

    /**
     * The stored time a scheduled deletion runs up to; null when none is
     * scheduled (NULL, or 0 as the marker reads it). Unreadable is an error,
     * never "none".
     */
    private static function cutoffTime(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $s = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : '');
        if (preg_match('/^[0-9]{1,10}$/D', $s) !== 1 || (int) $s > 4294967295) {
            throw new \UnexpectedValueException('user 1\'s user_delete_data_before is ' . var_export($raw, true)
                . ', not a time; nothing was deleted');
        }

        return (int) $s === 0 ? null : (int) $s;
    }

    /** The stored marker; null when none is scheduled. Unreadable is an error, never "none". */
    private static function marker(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        $s = is_int($raw) ? (string) $raw : (is_string($raw) ? $raw : '');
        if (preg_match('/^[0-9]{1,20}$/D', $s) !== 1) {
            throw new \UnexpectedValueException('user 1\'s user_delete_data_clickid is ' . var_export($raw, true) . ', not a click id; nothing was deleted');
        }

        return (int) $s === 0 ? null : (int) $s;
    }

    /** @return array{clicks: int, rows: array<string, int>, batches: int, complete: bool} */
    private static function emptyReport(): array
    {
        return ['clicks' => 0, 'rows' => array_fill_keys(self::TABLES, 0), 'batches' => 0, 'complete' => false];
    }

    /**
     * @param array{clicks: int, rows: array<string, int>, batches: int, complete: bool} $report
     * @param array<string, int> $rows
     * @return array{clicks: int, rows: array<string, int>, batches: int, complete: bool}
     */
    private static function add(array $report, array $rows, int $clicks): array
    {
        foreach ($rows as $table => $n) {
            $report['rows'][$table] += $n;
        }
        $report['clicks'] += $clicks;
        $report['batches']++;

        return $report;
    }
}
