<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Database\Connection;

/**
 * Rows of 202_attribution_exports: one export job each.
 *
 * Lifecycle: `pending` (queued_at says from when it may run: now for an
 * on-demand export, later for a scheduled one) → `running` (claimed by one
 * runner, attempts counted) → `completed`, or back to `pending` with a
 * later queued_at for a retryable webhook failure, or `failed` with the
 * reason in last_error. Claiming is a conditional UPDATE, so two runners
 * cannot both take a job.
 */
final class ExportStore
{
    public const STATUSES = ['pending', 'running', 'completed', 'failed'];

    private const COLUMNS = 'export_id, user_id, model_id, compare_model_id, group_by, range_start, range_end, status,
        file_path, rows_exported, webhook_url, webhook_secret, webhook_status_code, attempts, last_error,
        queued_at, started_at, completed_at, created_at, updated_at';

    public function __construct(private Connection $conn)
    {
    }

    public function insert(int $userId, int $modelId, ?int $compareModelId, string $groupBy, int $from, int $to, ?string $webhookUrl, ?string $webhookSecret, int $queuedAt): int
    {
        $now = time();
        $stmt = $this->conn->prepareWrite(
            "INSERT INTO 202_attribution_exports
                (user_id, model_id, compare_model_id, group_by, range_start, range_end, status, webhook_url, webhook_secret,
                 attempts, queued_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, 0, ?, ?, ?)"
        );
        $this->conn->bind($stmt, 'iiisiissiii', [$userId, $modelId, $compareModelId, $groupBy, $from, $to, $webhookUrl, $webhookSecret, $queuedAt, $now, $now]);
        $id = $this->conn->executeInsert($stmt);
        if ($id <= 0) {
            throw new \RuntimeException('the export job was not inserted');
        }

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function row(int $userId, int $exportId): ?array
    {
        $stmt = $this->conn->prepareWrite('SELECT ' . self::COLUMNS . ' FROM 202_attribution_exports WHERE user_id = ? AND export_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'ii', [$userId, $exportId]);

        return $this->conn->fetchOne($stmt);
    }

    /** @return array<string, mixed>|null the row, whoever owns it (the runner's read) */
    public function rowById(int $exportId): ?array
    {
        $stmt = $this->conn->prepareWrite('SELECT ' . self::COLUMNS . ' FROM 202_attribution_exports WHERE export_id = ? LIMIT 1');
        $this->conn->bind($stmt, 'i', [$exportId]);

        return $this->conn->fetchOne($stmt);
    }

    /** @return list<array<string, mixed>> newest first */
    public function rows(int $userId, ?string $status, int $limit): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM 202_attribution_exports WHERE user_id = ?'
            . ($status !== null ? ' AND status = ?' : '')
            . ' ORDER BY export_id DESC LIMIT ?';
        $stmt = $this->conn->prepareRead($sql);
        if ($status !== null) {
            $this->conn->bind($stmt, 'isi', [$userId, $status, $limit]);
        } else {
            $this->conn->bind($stmt, 'ii', [$userId, $limit]);
        }

        return $this->conn->fetchAll($stmt);
    }

    /** @return list<int> jobs whose time has come, oldest first */
    public function due(int $now, int $limit): array
    {
        $stmt = $this->conn->prepareWrite(
            "SELECT export_id FROM 202_attribution_exports WHERE status = 'pending' AND queued_at <= ? ORDER BY queued_at, export_id LIMIT ?"
        );
        $this->conn->bind($stmt, 'ii', [$now, $limit]);

        return array_map(static fn (array $r): int => (int) $r['export_id'], $this->conn->fetchAll($stmt));
    }

    /** Take a due job; false when another runner took it first or it is not due. */
    public function claim(int $exportId, int $now): bool
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_attribution_exports SET status = 'running', started_at = ?, attempts = attempts + 1, updated_at = ?
             WHERE export_id = ? AND status = 'pending' AND queued_at <= ?"
        );
        $this->conn->bind($stmt, 'iiii', [$now, $now, $exportId, $now]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    /**
     * Jobs left `running` by a runner that died: back to pending while they
     * have attempts left, failed after that. Returns how many were touched.
     */
    public function reclaimStale(int $now, int $staleAfter, int $maxAttempts): int
    {
        $cutoff = $now - $staleAfter;
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_attribution_exports
             SET status = IF(attempts >= ?, 'failed', 'pending'),
                 last_error = IF(attempts >= ?, 'The run that took this export stopped before finishing, too many times.', 'The run that took this export stopped before finishing; it will be tried again.'),
                 queued_at = ?, updated_at = ?
             WHERE status = 'running' AND started_at < ?"
        );
        $this->conn->bind($stmt, 'iiiii', [$maxAttempts, $maxAttempts, $now, $now, $cutoff]);

        return $this->conn->executeUpdate($stmt);
    }

    public function recordFile(int $exportId, string $fileName, int $rows): void
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_attribution_exports SET file_path = ?, rows_exported = ?, updated_at = ? WHERE export_id = ? AND status = 'running'"
        );
        $this->conn->bind($stmt, 'siii', [$fileName, $rows, time(), $exportId]);
        if ($this->conn->executeUpdate($stmt) !== 1) {
            throw new \RuntimeException('export ' . $exportId . ' is no longer running; its file was not recorded');
        }
    }

    /**
     * The three transitions out of `running` below are conditional on the
     * job still being running, which is this runner's ownership of it: a
     * job reclaimed as stale by another run, or deleted with its account or
     * model, matches no row. Each says whether it landed, so the runner can
     * tell "done" from "no longer mine" instead of reporting both as done.
     */
    public function complete(int $exportId, ?int $webhookStatus, int $now): bool
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_attribution_exports SET status = 'completed', webhook_status_code = ?, last_error = NULL, completed_at = ?, updated_at = ?
             WHERE export_id = ? AND status = 'running'"
        );
        $this->conn->bind($stmt, 'iiii', [$webhookStatus, $now, $now, $exportId]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    /** Back to pending, to be tried again from $retryAt. */
    public function deferRetry(int $exportId, string $error, ?int $webhookStatus, int $retryAt, int $now): bool
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_attribution_exports SET status = 'pending', last_error = ?, webhook_status_code = ?, queued_at = ?, updated_at = ?
             WHERE export_id = ? AND status = 'running'"
        );
        $this->conn->bind($stmt, 'siiii', [mb_substr($error, 0, 2000), $webhookStatus, $retryAt, $now, $exportId]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    public function fail(int $exportId, string $error, ?int $webhookStatus, int $now): bool
    {
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_attribution_exports SET status = 'failed', last_error = ?, webhook_status_code = ?, completed_at = ?, updated_at = ?
             WHERE export_id = ? AND status = 'running'"
        );
        $this->conn->bind($stmt, 'siiii', [mb_substr($error, 0, 2000), $webhookStatus, $now, $now, $exportId]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    /** A failed job, queued again from now with its attempts reset. False when it is not failed. */
    public function retry(int $userId, int $exportId): bool
    {
        $now = time();
        $stmt = $this->conn->prepareWrite(
            "UPDATE 202_attribution_exports SET status = 'pending', attempts = 0, last_error = NULL, webhook_status_code = NULL,
                queued_at = ?, started_at = NULL, completed_at = NULL, updated_at = ?
             WHERE user_id = ? AND export_id = ? AND status = 'failed'"
        );
        $this->conn->bind($stmt, 'iiii', [$now, $now, $userId, $exportId]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    /** Delete a job that is not running; false when it is running or gone. */
    public function delete(int $userId, int $exportId): bool
    {
        $stmt = $this->conn->prepareWrite("DELETE FROM 202_attribution_exports WHERE user_id = ? AND export_id = ? AND status <> 'running'");
        $this->conn->bind($stmt, 'ii', [$userId, $exportId]);

        return $this->conn->executeUpdate($stmt) === 1;
    }

    /**
     * Lock a model's export rows (as the model or the comparison) for the
     * transaction that is about to delete them, and return the ids of those
     * a runner holds.
     *
     * The lock is what makes a model delete and the export runner agree: a
     * runner's claim (an UPDATE of a pending row) waits for the delete to
     * commit and then matches nothing, and a job it already holds is seen
     * here as `running`, which the delete refuses — as DELETE of a single
     * running export does — rather than pulling the row out from under the
     * file the runner is writing.
     *
     * @return list<int>
     */
    public function lockForModelDelete(int $userId, int $modelId): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT export_id, status FROM 202_attribution_exports WHERE user_id = ? AND (model_id = ? OR compare_model_id = ?) FOR UPDATE'
        );
        $this->conn->bind($stmt, 'iii', [$userId, $modelId, $modelId]);
        $running = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            if ((string) $row['status'] === 'running') {
                $running[] = (int) $row['export_id'];
            }
        }

        return $running;
    }

    /**
     * The stored file names of an account's exports, or of one model's.
     *
     * @return list<string>
     */
    public function fileNames(int $userId, ?int $modelId = null): array
    {
        $stmt = $this->conn->prepareWrite(
            'SELECT file_path FROM 202_attribution_exports WHERE user_id = ? AND file_path IS NOT NULL'
            . ($modelId !== null ? ' AND (model_id = ? OR compare_model_id = ?)' : '')
        );
        if ($modelId !== null) {
            $this->conn->bind($stmt, 'iii', [$userId, $modelId, $modelId]);
        } else {
            $this->conn->bind($stmt, 'i', [$userId]);
        }

        return array_map(static fn (array $r): string => (string) $r['file_path'], $this->conn->fetchAll($stmt));
    }
}
