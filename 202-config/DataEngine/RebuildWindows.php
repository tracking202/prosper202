<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

use Prosper202\Database\Connection;

/**
 * The report rebuild windows, 202_dataengine_job: every write of the table
 * is here (QueueStatesHaveOneWriterTest), each state change a move
 * RebuildWindowState allows, asked of the row in the statement's own WHERE.
 *
 * A run that takes a window holds a server lock named for it (QueueLock)
 * until it marks or releases it. A window found `processing` whose lock is
 * free was claimed by a run that has gone: it is released and taken again.
 * The table has no key, so a window is its bounds, and every row with the
 * same bounds moves together.
 *
 * Every method throws on a statement the server refuses (Connection).
 *
 * @phpstan-type Window array{from: int, to: int}
 */
final class RebuildWindows
{
    private const QUEUE = 'INSERT INTO 202_dataengine_job SET time_from = ?, time_to = ?, '
        . RebuildWindowState::SET_PENDING;

    private const CLAIM = 'UPDATE 202_dataengine_job SET ' . RebuildWindowState::SET_PROCESSING
        . ' WHERE time_from = ? AND time_to = ? AND ' . RebuildWindowState::IS_PENDING;

    private const COMPLETE = 'UPDATE 202_dataengine_job SET ' . RebuildWindowState::SET_COMPLETED
        . ' WHERE time_from = ? AND time_to = ? AND ' . RebuildWindowState::IS_PROCESSING;

    private const RELEASE = 'UPDATE 202_dataengine_job SET ' . RebuildWindowState::SET_PENDING
        . ' WHERE time_from = ? AND time_to = ? AND ' . RebuildWindowState::IS_PROCESSING;

    public function __construct(private readonly Connection $conn)
    {
    }

    public function queue(int $from, int $to): void
    {
        $this->write(self::QUEUE, ['from' => $from, 'to' => $to]);
    }

    /** Whether any window is still to be rebuilt. */
    public function anyUnfinished(): bool
    {
        return $this->conn->fetchOne($this->conn->prepareWrite(
            'SELECT 1 AS found FROM 202_dataengine_job WHERE NOT (' . RebuildWindowState::IS_COMPLETED . ') LIMIT 1'
        )) !== null;
    }

    /**
     * Claims the earliest window no live run holds, for the caller to
     * rebuild and then complete() or release(). Null when there is none.
     *
     * @return Window|null
     */
    public function take(): ?array
    {
        $unfinished = $this->conn->fetchAll($this->conn->prepareWrite(
            'SELECT DISTINCT time_from, time_to FROM 202_dataengine_job WHERE NOT ('
            . RebuildWindowState::IS_COMPLETED . ') ORDER BY time_from, time_to'
        ));
        foreach ($unfinished as $row) {
            $window = ['from' => (int) $row['time_from'], 'to' => (int) $row['time_to']];
            $lock = $this->lockName($window);
            if (!QueueLock::tryTake($this->conn, $lock)) {
                continue;
            }
            try {
                // The lock is this run's, so a claim found on the window was left by a run that has gone.
                if ($this->write(self::RELEASE, $window) > 0) {
                    error_log('DataEngine rebuild: window ' . $window['from'] . '-' . $window['to']
                        . ' was claimed by a run that ended without marking or releasing it; taken again.');
                }
                $claimed = $this->write(self::CLAIM, $window) > 0;
            } catch (\Throwable $e) {
                $this->unlock($lock);
                throw $e;
            }
            if ($claimed) {
                return $window;
            }
            // Completed since it was read.
            $this->unlock($lock);
        }

        return null;
    }

    /**
     * The window take() gave is rebuilt. False when it is not this run's
     * (its lock is not held here) or no longer `processing`: nothing was
     * written.
     *
     * @param Window $window
     */
    public function complete(array $window): bool
    {
        return $this->finish($window, self::COMPLETE);
    }

    /**
     * The window take() gave was not rebuilt: the next run takes it. False
     * as for complete().
     *
     * @param Window $window
     */
    public function release(array $window): bool
    {
        return $this->finish($window, self::RELEASE);
    }

    /** The statement behind "N of M windows rebuilt": one row, `total` and `done`. */
    public static function progressSql(): string
    {
        return 'SELECT COUNT(*) AS total, COALESCE(SUM(' . RebuildWindowState::IS_COMPLETED
            . '), 0) AS done FROM 202_dataengine_job';
    }

    /** The latest $limit windows with their flags, for RebuildWindowState::ofRow(). */
    public static function recentSql(int $limit): string
    {
        return 'SELECT time_from, time_to, processing, processed FROM 202_dataengine_job ORDER BY time_from DESC'
            . ' LIMIT ' . max(1, $limit);
    }

    /** @param Window $window */
    private function finish(array $window, string $sql): bool
    {
        $lock = $this->lockName($window);
        if (!QueueLock::isHeldHere($this->conn, $lock)) {
            return false;
        }
        try {
            return $this->write($sql, $window) > 0;
        } finally {
            $this->unlock($lock);
        }
    }

    /**
     * @param Window $window
     * @return int rows written: 0 when the window is not in the state $sql asks for
     */
    private function write(string $sql, array $window): int
    {
        $stmt = $this->conn->prepareWrite($sql);
        $this->conn->bind($stmt, 'ii', [$window['from'], $window['to']]);

        return $this->conn->executeUpdate($stmt);
    }

    /** @param Window $window */
    private function lockName(array $window): string
    {
        return QueueLock::name($this->conn, 'rebuild window ' . $window['from'] . '-' . $window['to']);
    }

    /** Never throws: the window's state is written by now, and the lock goes with the connection. */
    private function unlock(string $lock): void
    {
        try {
            QueueLock::release($this->conn, $lock);
        } catch (\Throwable $e) {
            error_log('DataEngine rebuild: a window\'s lock was not released: ' . $e->getMessage());
        }
    }
}
