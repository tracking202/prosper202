<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Database\Connection;

/**
 * Brings the conversions recorded before the upgrade into multi-touch
 * attribution (Codex P1 on PR #157).
 *
 * Every write the ledger makes queues what it changed for the worker, but
 * nothing queued what was there before it: an upgraded install's MTA
 * reports were empty for every range before the upgrade. Those clicks'
 * rows are marked pre_ledger (the upgrade cannot re-add them into the value
 * the old writes left in the click), so they never count on their own; the
 * value that stands for them is the click's cached payout, which the ledger
 * carries in as a legacy_baseline row the first time it writes the click
 * (MysqlConversionLedger::ensureManaged()). The backfill does exactly that
 * for every pre-upgrade lead click and queues the baseline, reason
 * `backfill`, so the worker builds its journey and credits like any other
 * conversion.
 *
 * Bounded and resumable, never in the upgrade request: the upgrade only
 * writes the marker (202_attribution_backfill: through_click_id is the
 * newest click at the upgrade), and each worker run walks the clicks by
 * primary key in chunks of CHUNK ids until its deadline, moving the cursor
 * after each chunk. Idempotent: a click already managed — by a ledger write
 * since the upgrade, or by an earlier pass over a chunk the cursor had not
 * recorded — gets no second baseline and is not queued again (its writer
 * queued it). Each click is carried in under its own lock, in its own
 * transaction, as the ledger's writers do.
 *
 * The baseline's conversion time is the click's time (ensureManaged()), so
 * a backfilled conversion reports on the day of its click.
 */
final class ConversionBackfill
{
    public const REASON = 'backfill';

    /**
     * What the upgrade runs (_upgrade_measurement_tables()): the marker, the
     * newest click at the upgrade, and nothing walked. INSERT IGNORE, so a
     * re-run of the step keeps a walk where it is.
     */
    public const MARK_SQL = 'INSERT IGNORE INTO `202_attribution_backfill`'
        . ' (`backfill_id`, `next_click_id`, `through_click_id`, `started_at`) '
        . 'SELECT 1, 0, COALESCE(MAX(`click_id`), 0), UNIX_TIMESTAMP() FROM `202_clicks`';

    /** Click ids per step: a range, not a count, so a step's work is bounded however sparse the leads. */
    public const CHUNK = 5000;

    /** @param (callable(): int)|null $clock */
    public function __construct(private readonly Connection $conn, private $clock = null)
    {
        $this->clock ??= static fn (): int => time();
    }

    /**
     * Walk until $deadline (unix seconds) or the end. Returns null when
     * there is nothing to backfill (no marker, or finished), otherwise what
     * this run did and where the walk stands.
     *
     * @return array{clicks: int, baselines: int, next_click_id: int, through_click_id: int, finished: bool}|null
     */
    public function run(int $deadline): ?array
    {
        $state = $this->state();
        if ($state === null || $state['finished_at'] !== null) {
            return null;
        }
        $ledger = new MysqlConversionLedger($this->conn);
        $next = $state['next_click_id'];
        $through = $state['through_click_id'];
        $out = [
            'clicks' => 0,
            'baselines' => 0,
            'next_click_id' => $next,
            'through_click_id' => $through,
            'finished' => false,
        ];

        // At least one chunk per run, so a backlog always moves.
        do {
            if ($next > $through) {
                break;
            }
            $end = min($next + self::CHUNK - 1, $through);
            $stmt = $this->conn->prepareWrite(
                'SELECT click_id FROM 202_clicks WHERE click_id BETWEEN ? AND ? AND click_lead = 1 ORDER BY click_id'
            );
            $this->conn->bind($stmt, 'ii', [$next, $end]);
            $clicks = array_map(static fn (array $r): int => (int) $r['click_id'], $this->conn->fetchAll($stmt));

            $baselines = 0;
            foreach ($clicks as $clickId) {
                $baselines += $this->conn->transaction(function () use ($clickId, $ledger): int {
                    $lock = $this->conn->prepareWrite(
                        'SELECT click_id, user_id, aff_campaign_id, click_payout, click_time, click_lead FROM 202_clicks
                         WHERE click_id = ? LIMIT 1 FOR UPDATE'
                    );
                    $this->conn->bind($lock, 'i', [$clickId]);
                    $click = $this->conn->fetchOne($lock);
                    if ($click === null) {
                        return 0;
                    }
                    $baselineId = $ledger->ensureManaged($click, (int) $click['user_id']);
                    if ($baselineId === null) {
                        return 0; // not a lead any more, or already managed: its writer queued it
                    }
                    $ledger->enqueue([$baselineId], self::REASON);

                    return 1;
                });
            }

            $chunkStart = $next;
            $next = $end + 1;
            $finished = $next > $through;
            // Moved from where this chunk started, or not at all: a second
            // walker (the lock rules one out; this is the belt) that already
            // moved it must not be moved back.
            $move = $this->conn->prepareWrite(
                'UPDATE 202_attribution_backfill
                 SET next_click_id = ?, clicks_examined = clicks_examined + ?, baselines = baselines + ?,
                     finished_at = ?
                 WHERE backfill_id = 1 AND next_click_id = ?'
            );
            $finishedAt = $finished ? ($this->clock)() : null;
            $this->conn->bind($move, 'iiiii', [$next, count($clicks), $baselines, $finishedAt, $chunkStart]);
            if ($this->conn->executeUpdate($move) !== 1) {
                throw new \RuntimeException(
                    'the attribution backfill cursor moved under this run (expected ' . $chunkStart . ')'
                );
            }

            $out['clicks'] += count($clicks);
            $out['baselines'] += $baselines;
            $out['next_click_id'] = $next;
            $out['finished'] = $finished;
        } while (!$out['finished'] && ($this->clock)() < $deadline);

        return $out;
    }

    /**
     * The walk's state, or null when this installation has none (a fresh
     * install, which has nothing from before the ledger).
     *
     * @return array{
     *     next_click_id: int, through_click_id: int, started_at: int, finished_at: int|null,
     *     clicks_examined: int, baselines: int
     * }|null
     */
    public function state(): ?array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT next_click_id, through_click_id, started_at, finished_at, clicks_examined, baselines
             FROM 202_attribution_backfill WHERE backfill_id = 1'
        );
        $row = $this->conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        return [
            'next_click_id' => (int) $row['next_click_id'],
            'through_click_id' => (int) $row['through_click_id'],
            'started_at' => (int) $row['started_at'],
            'finished_at' => $row['finished_at'] !== null ? (int) $row['finished_at'] : null,
            'clicks_examined' => (int) $row['clicks_examined'],
            'baselines' => (int) $row['baselines'],
        ];
    }

    /**
     * What a report says while the walk is unfinished: null once it is done
     * (or never started), else how far it is.
     *
     * @return array{
     *     in_progress: true, started_at: int, clicks_examined: int, baselines_queued: int, percent: int, note: string
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
            'clicks_examined' => $s['clicks_examined'],
            'baselines_queued' => $s['baselines'],
            'percent' => $percent,
            'note' => 'Conversions recorded before the upgrade are still being brought into attribution; '
                . 'reports over dates before the upgrade are incomplete until this finishes.',
        ];
    }
}
