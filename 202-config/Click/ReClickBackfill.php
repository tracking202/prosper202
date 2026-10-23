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
 * cursor at that click, so the next run starts with it: a failure is
 * retried, never stepped over. A click whose rows name more than one
 * account is left as it is, logged, and counted.
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
     *     reclicked_clicks: int, same_second_ties: int, mixed_accounts: int, next_click_id: int,
     *     through_click_id: int, finished: bool, failed_click_id: int|null
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
            'next_click_id' => $next,
            'through_click_id' => $through,
            'finished' => false,
            'failed_click_id' => null,
        ];

        $repairs = 0;
        do {
            $end = min($next + self::CHUNK - 1, $through);
            $done = ['reclicked_clicks' => 0, 'same_second_ties' => 0, 'mixed_accounts' => 0];
            $failed = null;
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
                    self::logFailure($clickId, $e);
                    $failed = $clickId;
                    break;
                }
            }

            $chunkStart = $next;
            $next = $failed ?? $stopped ?? max($end + 1, $chunkStart);
            $finished = $failed === null && $stopped === null && $next > $through;
            $this->moveCursor($chunkStart, $next, $done, $finished);

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
     *     reclicked_clicks: int, same_second_ties: int, mixed_accounts: int
     * }|null
     */
    public function state(): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT next_click_id, through_click_id, started_at, finished_at, reclicked_clicks, same_second_ties,'
            . ' mixed_accounts FROM 202_reclick_backfill WHERE backfill_id = 1'
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
        ];
    }

    /**
     * What a report says while the walk is unfinished: null once it is done
     * (or never started), else how far it is.
     *
     * @return array{
     *     in_progress: true, started_at: int, reclicked_clicks: int, same_second_ties: int, percent: int,
     *     note: string
     * }|null
     */
    public function progress(): ?array
    {
        $s = $this->state();
        if ($s === null || $s['finished_at'] !== null) {
            return null;
        }
        $percent = $s['through_click_id'] <= 0 ? 100
            : (int) floor(100 * min($s['next_click_id'], $s['through_click_id']) / $s['through_click_id']);

        return [
            'in_progress' => true,
            'started_at' => $s['started_at'],
            'reclicked_clicks' => $s['reclicked_clicks'],
            'same_second_ties' => $s['same_second_ties'],
            'percent' => $percent,
            'note' => 'Clicks re-clicked through a redirector before the upgrade are still being brought to one'
                . ' visit each; until this finishes, reports over dates before the upgrade count such a click at'
                . ' each of its visits, under that visit\'s traffic source and cost.',
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
     * @param array{reclicked_clicks: int, same_second_ties: int, mixed_accounts: int} $done
     */
    private function moveCursor(int $from, int $to, array $done, bool $finished): void
    {
        if ($to === $from && array_sum($done) === 0 && !$finished) {
            return;
        }
        $move = $this->conn->prepareWrite(
            'UPDATE 202_reclick_backfill SET next_click_id = ?, reclicked_clicks = reclicked_clicks + ?,'
            . ' same_second_ties = same_second_ties + ?, mixed_accounts = mixed_accounts + ?, finished_at = ?'
            . ' WHERE backfill_id = 1 AND next_click_id = ?'
        );
        $this->conn->bind($move, 'iiiiii', [
            $to, $done['reclicked_clicks'], $done['same_second_ties'], $done['mixed_accounts'],
            $finished ? ($this->clock)() : null, $from,
        ]);
        if ($this->conn->executeUpdate($move) !== 1) {
            throw new \RuntimeException('the re-click backfill cursor moved under this run (expected ' . $from . ')');
        }
    }

    /** One line per failed click, with the values the server's words quote taken out. */
    private static function logFailure(int $clickId, \Throwable $e): void
    {
        require_once dirname(__DIR__) . '/mysql-error-args.php';
        $message = $e->getMessage();
        $errno = preg_match('/ \[errno (\d+)\]\z/', $message, $m) === 1 ? (int) $m[1]
            : ($e instanceof \mysqli_sql_exception ? (int) $e->getCode() : 0);
        error_log('p202 re-click backfill: click ' . $clickId . ' could not be brought to its first visit\'s'
            . ' source and its latest visit (' . $e::class . ': ' . p202FailedQueryMessage($errno, $message)
            . '); the next run starts with it');
    }
}
