<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;
use Prosper202\DataEngine\ClickRollupSql;

/**
 * Brings the clicks re-clicked before 1.9.79 to the rule a re-click is
 * written by since (MysqlClickRepository::recordReClick()).
 *
 * A re-click's row used to take the redirector link's traffic source and
 * cost, no lead and its own filtered and bot flags; and a click's latest
 * visit is now read from 202_click_latest_visits, which nothing wrote for
 * them. So such a click counted at each visit, and once it counts at its
 * latest, a converted click would lose its lead and income everywhere (a
 * conversion's once-per-click check asks every visit,
 * LatestVisit::anyVisitIsLead()). For every click with more
 * than one 202_clicks row, MysqlClickRepository::repairVisits() gives each
 * row the first visit's source, cost and flags and the click's lead and
 * value, records the latest visit and marks the click for the attribution
 * rollup, in one transaction under the click's lock; the click's report
 * rows are then rolled up again (ClickRollupSql::rollUpClick()).
 *
 * Bounded and resumable, never in the upgrade request: the upgrade writes
 * the marker (202_reclick_backfill: through_click_id is the newest click at
 * the upgrade), and each cron run walks click ids in chunks of CHUNK ids,
 * moving the cursor after each chunk, until its deadline. The deadline is
 * asked before each click, not each chunk: a chunk dense with re-clicks
 * took 85 s (measured, 158 partitions), holding up every job after it in
 * the cron's minute; a run that stops mid-chunk leaves the cursor at the
 * first click it did not repair. The clicks of a chunk are found by a plain
 * read, which locks nothing. Idempotent: a click repaired twice is written
 * the same values twice.
 *
 * A click that fails is logged, naming it, and the run ends there with the
 * cursor at that click, so the next run starts with it. The click, how many
 * runs it failed with an error of its own and since when are kept with the
 * cursor until a run gets past it, and progress() says the walk is stopped
 * there: a click
 * that fails every minute holds back every click after it, which a
 * percentage alone read as a walk under way. Once it has failed with an
 * error of its own in STEP_OVER_RUNS runs over at least STEP_OVER_SECONDS,
 * with no run getting past it, the failure is the click's (a value its rows
 * hold): it is logged, counted and stepped over, and stays counted at each
 * visit, as every click the walk has not reached is. An error of the moment
 * (MOMENT_ERRNOS: a lock, a timeout, a lost connection, a server that will
 * not write) stops the run at the click the same way and counts towards
 * nothing, however long it lasts, as does an error of the install
 * (INSTALL_ERRNOS: a permission refused, a table missing) and a failure
 * that names no cause (no errno and no value of the click's: a type error,
 * a wrapper that dropped the errno). What counts is a server error of another errno, or a value the
 * click's rows hold that reads wrong, and the hour runs from the first run
 * that failed so. A click whose rows name more than one account is left as it
 * is, logged, and counted.
 *
 * Two of a click's rows in its latest second are both its latest visit, and
 * every per-row sum counts the click twice (the report rollup, keyed by
 * click and time, folds them). They are counted, not folded: the table has
 * no key to tell which of the two the visitor made last.
 */
final class ReClickBackfill
{
    /** Click ids per step: a range, so a step's work is bounded however sparse the re-clicks. */
    public const CHUNK = 5000;

    /** How long one cron run walks. */
    public const RUN_SECONDS = 10;

    /** Runs a click fails with an error of its own before it is stepped over, with STEP_OVER_SECONDS. */
    public const STEP_OVER_RUNS = 10;

    /** How long a click fails, from its first run that failed with an error of its own, before it is stepped over. */
    public const STEP_OVER_SECONDS = 3600;

    /**
     * The server's errors that say something about the moment, not the
     * click: a lock wait or deadlock, a statement interrupted or timed out
     * (1317, MariaDB's 1969, MySQL's 3024), a connection killed, refused or
     * timed out (1927, 1040, 1203, 4031, and every client error, 2000-2999),
     * a network read or write that failed or was interrupted (1158-1161), a
     * server shutting down (1053), out of memory (1037, 1038, 1041), full
     * (1021, 1114) or read-only (1290, 1792, 1836), the storage engine's
     * own errors (1030, a temporary one 1297, one during commit 1180), a
     * limit reached (lock table 1206, prepared statements 1461, threads 1135,
     * the user's resources 1226), and a statement to prepare again (1615).
     */
    public const MOMENT_ERRNOS = [
        1205, 1213, 1317, 1969, 3024, 1927, 1040, 1203, 4031, 1158, 1159, 1160, 1161, 1053, 1037, 1038, 1041,
        1021, 1114, 1290, 1792, 1836, 1030, 1297, 1180, 1206, 1461, 1135, 1226, 1615,
    ];

    /**
     * The server's errors that say something about the install, not the
     * click, and fail every click alike: a permission refused (1044, 1045,
     * 1142, 1143), a table or column missing (1146, 1054) or changed under
     * the statement (1412). They count in none, so the walk waits at the
     * click until the install is mended instead of stepping one click over
     * an hour for good.
     */
    public const INSTALL_ERRNOS = [1044, 1045, 1142, 1143, 1146, 1054, 1412];

    /** @var callable(): int */
    private $clock;

    /** @param (callable(): int)|null $clock */
    public function __construct(private readonly Connection $conn, ?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * The upgrade's marker: the walk up to $throughClickId, nothing walked.
     * INSERT IGNORE, so a re-run of the step keeps a walk where it is. The
     * newest click is read first, by a statement of its own: an INSERT …
     * SELECT reads 202_clicks under shared locks, the gap after the newest
     * click's rows among them, where every new click is inserted.
     */
    public static function markSql(int $throughClickId): string
    {
        if ($throughClickId < 0) {
            throw new \InvalidArgumentException('A click id is 0 or more, not ' . $throughClickId);
        }

        return 'INSERT IGNORE INTO `202_reclick_backfill` (`backfill_id`, `next_click_id`, `through_click_id`,'
            . ' `started_at`) VALUES (1, 0, ' . $throughClickId . ', UNIX_TIMESTAMP())';
    }

    /** The newest click, for markSql(): a plain read. */
    public const NEWEST_CLICK_SQL = 'SELECT COALESCE(MAX(`click_id`), 0) AS `newest` FROM `202_clicks`';

    /**
     * Walk until $deadline (unix seconds) or the end, at least one click.
     * Null when there is nothing to walk (no marker, or finished).
     *
     * @return array{
     *     reclicked_clicks: int, same_second_ties: int, mixed_accounts: int, skipped_clicks: int,
     *     next_click_id: int, through_click_id: int, finished: bool, failed_click_id: int|null
     * }|null
     */
    public function run(int $deadline): ?array
    {
        $state = $this->state();
        if ($state === null || $state['finished_at'] !== null) {
            return null;
        }
        $repo = new MysqlClickRepository($this->conn);
        $next = $state['next_click_id'];
        $through = $state['through_click_id'];
        $out = [
            'reclicked_clicks' => 0,
            'same_second_ties' => 0,
            'mixed_accounts' => 0,
            'skipped_clicks' => 0,
            'next_click_id' => $next,
            'through_click_id' => $through,
            'finished' => false,
            'failed_click_id' => null,
        ];

        $repairs = 0;
        do {
            $end = min($next + self::CHUNK - 1, $through);
            $done = ['reclicked_clicks' => 0, 'same_second_ties' => 0, 'mixed_accounts' => 0, 'skipped_clicks' => 0];
            $skipped = null;
            $failed = null;
            $failedOwn = false;
            $stopped = null;
            foreach ($this->reClickedClicks($next, $end) as $clickId) {
                if ($repairs > 0 && ($this->clock)() >= $deadline) {
                    $stopped = $clickId;
                    break;
                }
                $repairs++;
                try {
                    $repair = $repo->repairVisits($clickId);
                    if ($repair['outcome'] === 'repaired') {
                        ClickRollupSql::rollUpClick($this->conn, $clickId);
                        $done['reclicked_clicks']++;
                        $done['same_second_ties'] += $repair['tie'] ? 1 : 0;
                    } elseif ($repair['outcome'] === 'accounts') {
                        $done['mixed_accounts']++;
                        error_log('p202 re-click backfill: click ' . $clickId . ' has rows of more than one'
                            . ' account; they are left as they are');
                    }
                } catch (\Throwable $e) {
                    $own = self::isTheClicks($e);
                    if ($own && $this->stepsOver($state, $clickId)) {
                        self::logFailure($clickId, $e, $state['failed_runs'] + 1);
                        $done['skipped_clicks']++;
                        $skipped = $clickId;
                        continue;
                    }
                    self::logFailure($clickId, $e);
                    $failed = $clickId;
                    $failedOwn = $own;
                    break;
                }
            }

            $chunkStart = $next;
            $next = $failed ?? $stopped ?? max($end + 1, $chunkStart);
            $finished = $failed === null && $stopped === null && $next > $through;
            $this->moveCursor($chunkStart, $next, $done, $finished, $skipped);
            // An error of the moment at the click the walk was already
            // stopped at changes nothing (the run that stops there first
            // moves nothing), and an UPDATE that changes nothing reports no row.
            if ($failed !== null && ($failedOwn || $state['failed_click_id'] !== $failed)) {
                $this->recordFailure($failed, $failedOwn);
            }

            foreach ($done as $count => $n) {
                $out[$count] += $n;
            }
            $out['next_click_id'] = $next;
            $out['finished'] = $finished;
            $out['failed_click_id'] = $failed;
        } while ($failed === null && $stopped === null && !$finished && ($this->clock)() < $deadline);

        return $out;
    }

    /**
     * The walk's state, or null when this installation has none (a fresh
     * install, whose re-clicks have all been written by the rule).
     *
     * @return array{
     *     next_click_id: int, through_click_id: int, started_at: int, finished_at: int|null,
     *     reclicked_clicks: int, same_second_ties: int, mixed_accounts: int,
     *     failed_click_id: int|null, failed_runs: int, failing_since: int|null,
     *     skipped_clicks: int, last_skipped_click_id: int|null
     * }|null
     */
    public function state(): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT next_click_id, through_click_id, started_at, finished_at, reclicked_clicks, same_second_ties,'
            . ' mixed_accounts, failed_click_id, failed_runs, failing_since, skipped_clicks, last_skipped_click_id'
            . ' FROM 202_reclick_backfill'
            . ' WHERE backfill_id = 1'
        );
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }
        $int = static function (string $column) use ($row): int {
            $value = $row[$column] ?? null;
            if (is_int($value) || (is_string($value) && preg_match('/\A\d+\z/', $value) === 1)) {
                return (int) $value;
            }
            throw new \UnexpectedValueException("202_reclick_backfill.$column read as " . get_debug_type($value));
        };

        return [
            'next_click_id' => $int('next_click_id'),
            'through_click_id' => $int('through_click_id'),
            'started_at' => $int('started_at'),
            'finished_at' => $row['finished_at'] === null ? null : $int('finished_at'),
            'reclicked_clicks' => $int('reclicked_clicks'),
            'same_second_ties' => $int('same_second_ties'),
            'mixed_accounts' => $int('mixed_accounts'),
            'failed_click_id' => $row['failed_click_id'] === null ? null : $int('failed_click_id'),
            'failed_runs' => $int('failed_runs'),
            'failing_since' => $row['failing_since'] === null ? null : $int('failing_since'),
            'skipped_clicks' => $int('skipped_clicks'),
            'last_skipped_click_id' => $row['last_skipped_click_id'] === null ? null : $int('last_skipped_click_id'),
        ];
    }

    /**
     * What a report says while the walk is unfinished, or finished with
     * clicks stepped over: null when it is done with none (or never started),
     * else how far it is; when the run stopped at a click that failed, which
     * click, in how many runs with an error of its own and since when
     * (stalled_at_click_id, failed_runs, failing_since; null, 0 and null while
     * nothing fails); and
     * how many clicks were stepped over, the latest by id.
     *
     * @return array{
     *     in_progress: bool, started_at: int, reclicked_clicks: int, same_second_ties: int, percent: int,
     *     stalled_at_click_id: int|null, failed_runs: int, failing_since: int|null,
     *     skipped_clicks: int, last_skipped_click_id: int|null, note: string
     * }|null
     */
    public function progress(): ?array
    {
        $s = $this->state();
        if ($s === null || ($s['finished_at'] !== null && $s['skipped_clicks'] === 0)) {
            return null;
        }
        $percent = $s['through_click_id'] <= 0 ? 100
            : (int) floor(100 * min($s['next_click_id'], $s['through_click_id']) / $s['through_click_id']);
        $note = 'Clicks re-clicked through a redirector before the upgrade are still being brought to one'
            . ' visit each; until this finishes, reports over dates before the upgrade count such a click at'
            . ' each of its visits, under that visit\'s traffic source and cost.';
        if ($s['finished_at'] !== null) {
            $note = 'The walk is done.';
        } elseif ($s['failed_click_id'] !== null && $s['failed_runs'] === 0) {
            $note = 'The walk is stopped at click ' . $s['failed_click_id'] . ', which fails with an error that is'
                . ' not tied to its own data (a lock, a timeout, a lost connection, a permission or table the'
                . ' install lacks, or an error that names no cause), so it is not stepped over; the server\'s'
                . ' error log says why ("p202 re-click backfill: click ' . $s['failed_click_id'] . ' ..."), and'
                . ' each cron run tries it again. It moves on once that error clears; until then, reports over'
                . ' dates before the upgrade count it, and every re-clicked click after it, at each of its visits.';
        } elseif ($s['failed_click_id'] !== null) {
            $note = 'The walk is stopped at click ' . $s['failed_click_id'] . ', which has failed with an error of'
                . ' its own in ' . $s['failed_runs'] . ' run' . ($s['failed_runs'] === 1 ? '' : 's') . ' (an error'
                . ' not tied to its own data counts in none); the server\'s'
                . ' error log says why ("p202 re-click backfill: click ' . $s['failed_click_id'] . ' ..."), and'
                . ' each cron run tries it again,'
                . ' stepping over it once it has failed with its own error in ' . self::STEP_OVER_RUNS
                . ' runs over an hour. Until then, reports over dates before the upgrade count it, and every'
                . ' re-clicked click after it, at each of its visits.';
        }
        if ($s['skipped_clicks'] > 0) {
            $note .= ' ' . $s['skipped_clicks'] . ' click' . ($s['skipped_clicks'] === 1 ? ' was' : 's were')
                . ' stepped over after failing for an hour of runs, the latest click ' . $s['last_skipped_click_id']
                . ' (the error log names each: "p202 re-click backfill: click ... stepped over"); reports count'
                . ' each at every one of its visits.';
        }

        return [
            'in_progress' => $s['finished_at'] === null,
            'started_at' => $s['started_at'],
            'reclicked_clicks' => $s['reclicked_clicks'],
            'same_second_ties' => $s['same_second_ties'],
            'percent' => $percent,
            'stalled_at_click_id' => $s['failed_click_id'],
            'failed_runs' => $s['failed_runs'],
            'failing_since' => $s['failing_since'],
            'skipped_clicks' => $s['skipped_clicks'],
            'last_skipped_click_id' => $s['last_skipped_click_id'],
            'note' => $note,
        ];
    }

    /**
     * The clicks between $from and $to with more than one 202_clicks row, by
     * a plain read (outside any transaction: a consistent read, no locks).
     *
     * @return list<int>
     */
    private function reClickedClicks(int $from, int $to): array
    {
        if ($to < $from) {
            return [];
        }
        $stmt = $this->conn->prepareWrite(
            'SELECT click_id FROM 202_clicks WHERE click_id BETWEEN ? AND ?'
            . ' GROUP BY click_id HAVING COUNT(*) > 1 ORDER BY click_id'
        );
        $this->conn->bind($stmt, 'ii', [$from, $to]);
        $clicks = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $id = $row['click_id'] ?? null;
            if (!is_int($id) && !(is_string($id) && preg_match('/\A[1-9]\d*\z/', $id) === 1)) {
                throw new \UnexpectedValueException('202_clicks.click_id read as ' . get_debug_type($id));
            }
            $clicks[] = (int) $id;
        }

        return $clicks;
    }

    /**
     * Move the cursor from where this chunk started, adding what it did. A
     * cursor another walker moved is not moved back (the cron's lock rules
     * one out; this is the belt).
     *
     * @param array{reclicked_clicks: int, same_second_ties: int, mixed_accounts: int, skipped_clicks: int} $done
     */
    private function moveCursor(int $from, int $to, array $done, bool $finished, ?int $skipped): void
    {
        if ($to === $from && array_sum($done) === 0 && !$finished) {
            return;
        }
        // A failed click the cursor has moved past is no longer where the
        // walk is stopped. The assignments run left to right, so the click
        // is cleared last.
        $move = $this->conn->prepareWrite(
            'UPDATE 202_reclick_backfill SET next_click_id = ?, reclicked_clicks = reclicked_clicks + ?,'
            . ' same_second_ties = same_second_ties + ?, mixed_accounts = mixed_accounts + ?, finished_at = ?,'
            . ' skipped_clicks = skipped_clicks + ?, last_skipped_click_id = COALESCE(?, last_skipped_click_id),'
            . ' failed_runs = IF(failed_click_id < ?, 0, failed_runs),'
            . ' failing_since = IF(failed_click_id < ?, NULL, failing_since),'
            . ' failed_click_id = IF(failed_click_id < ?, NULL, failed_click_id)'
            . ' WHERE backfill_id = 1 AND next_click_id = ?'
        );
        $this->conn->bind($move, 'iiiiiiiiiii', [
            $to, $done['reclicked_clicks'], $done['same_second_ties'], $done['mixed_accounts'],
            $finished ? ($this->clock)() : null, $done['skipped_clicks'], $skipped, $to, $to, $to, $from,
        ]);
        if ($this->conn->executeUpdate($move) !== 1) {
            throw new \RuntimeException('the re-click backfill cursor moved under this run (expected ' . $from . ')');
        }
    }

    /**
     * Keep the click the run stopped at with the cursor, and since when it
     * fails: failed_runs counts its runs that failed with an error of its own
     * ($own), and failing_since is the first of them; an error that is not
     * the click's neither adds to nor resets either. The assignments run left
     * to right, so the click is written last.
     */
    private function recordFailure(int $clickId, bool $own): void
    {
        $stmt = $this->conn->prepareWrite(
            'UPDATE 202_reclick_backfill SET failed_runs = IF(failed_click_id <=> ?, failed_runs + ?, ?),'
            . ' failing_since = IF(failed_click_id <=> ? AND failing_since IS NOT NULL, failing_since,'
            . ' IF(? = 1, ?, NULL)), failed_click_id = ?'
            . ' WHERE backfill_id = 1'
        );
        $count = $own ? 1 : 0;
        $this->conn->bind(
            $stmt,
            'iiiiiii',
            [$clickId, $count, $count, $clickId, $count, ($this->clock)(), $clickId]
        );
        if ($this->conn->executeUpdate($stmt) !== 1) {
            throw new \RuntimeException('the re-click backfill could not record that click ' . $clickId . ' failed');
        }
    }

    /**
     * Whether the click the run stopped at last time, failing again now with
     * an error of its own, has failed long enough to be stepped over.
     *
     * @param array{failed_click_id: int|null, failed_runs: int, failing_since: int|null} $state the run's start
     */
    private function stepsOver(array $state, int $clickId): bool
    {
        return $state['failed_click_id'] === $clickId
            && $state['failed_runs'] + 1 >= self::STEP_OVER_RUNS
            && $state['failing_since'] !== null
            && ($this->clock)() - $state['failing_since'] >= self::STEP_OVER_SECONDS;
    }

    /**
     * Whether the failure is the click's: nothing in its chain carries one of
     * MOMENT_ERRNOS or INSTALL_ERRNOS, and something names a cause — a server
     * errno (a mysqli_sql_exception's code, or the ` [errno N]` Connection
     * puts last) or a value of the click's rows that read wrong (the repair's
     * UnexpectedValueException). A failure that names neither is not counted:
     * a step-over cannot be undone, and an unknown is not evidence.
     */
    private static function isTheClicks(\Throwable $e): bool
    {
        $named = false;
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $errno = $current instanceof \mysqli_sql_exception ? (int) $current->getCode()
                : (preg_match('/ \[errno (\d+)\]\z/', $current->getMessage(), $m) === 1 ? (int) $m[1] : 0);
            if (
                in_array($errno, self::MOMENT_ERRNOS, true) || in_array($errno, self::INSTALL_ERRNOS, true)
                || ($errno >= 2000 && $errno <= 2999)
            ) {
                return false;
            }
            $named = $named || $errno > 0 || $current instanceof \UnexpectedValueException;
        }

        return $named;
    }

    /**
     * One line per failed click, with the values the server's words quote
     * taken out; $steppedOverAfter is the runs it failed with its own error,
     * when it is stepped over.
     */
    private static function logFailure(int $clickId, \Throwable $e, ?int $steppedOverAfter = null): void
    {
        require_once dirname(__DIR__) . '/mysql-error-args.php';
        $message = $e->getMessage();
        $errno = preg_match('/ \[errno (\d+)\]\z/', $message, $m) === 1 ? (int) $m[1]
            : ($e instanceof \mysqli_sql_exception ? (int) $e->getCode() : 0);
        error_log('p202 re-click backfill: click ' . $clickId . ' could not be brought to its first visit\'s'
            . ' source and its latest visit (' . $e::class . ': ' . p202FailedQueryMessage($errno, $message)
            . ($steppedOverAfter === null ? '); the next run starts with it'
                : '); stepped over after failing with its own error in ' . $steppedOverAfter . ' runs, so it is'
                . ' counted at each of its visits'));
    }
}
