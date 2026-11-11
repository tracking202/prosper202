<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Database\Connection;

/**
 * "Try again now" for one account: its failed conversions, identity merges
 * and model recomputes are made due at once, as the worker's --retry-now
 * does for the whole install, and the worker is run once, briefly, so the
 * asker hears the result; the minutely cron job carries on with the rest.
 */
final class QueueRetry
{
    public const RAN = 'ran';
    /** Another run held the worker's lock; it takes the rows next. */
    public const BUSY = 'busy';
    /** The run threw (logged); the rows stay due for the cron job. */
    public const FAILED = 'failed';

    private function __construct()
    {
    }

    /**
     * @return array{made_due: array{conversions: int, merges: int, models: int}, worker: 'ran'|'busy'|'failed'}
     */
    public static function tryNow(Connection $conn, int $userId, int $budgetSeconds = 5): array
    {
        $due = self::makeDue($conn, $userId);
        try {
            $worker = AttributionWorker::runExclusive($conn, $budgetSeconds) === null ? self::BUSY : self::RAN;
        } catch (\Throwable $e) {
            // The rows are due whatever became of this run: that is not undone or hidden.
            error_log('p202 attribution: the run after "try again now" failed: ' . get_class($e) . ': '
                . $e->getMessage());
            $worker = self::FAILED;
        }

        return ['made_due' => $due, 'worker' => $worker];
    }

    /** @return array{conversions: int, merges: int, models: int} what was made due */
    public static function makeDue(Connection $conn, int $userId): array
    {
        $stmt = $conn->prepareWrite(
            'UPDATE 202_attribution_pending p JOIN 202_conversion_logs cl ON cl.conv_id = p.conv_id
             SET p.retry_at = 0 WHERE cl.user_id = ? AND p.retry_at > 0'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $conversions = $conn->executeUpdate($stmt);

        $stmt = $conn->prepareWrite(
            'UPDATE 202_identity_merges SET requeue_retry_at = 0, requeue_retired_at = NULL
             WHERE user_id = ? AND requeued_at IS NULL AND (requeue_retry_at > 0 OR requeue_retired_at IS NOT NULL)'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $merges = $conn->executeUpdate($stmt);

        $stmt = $conn->prepareWrite(
            'UPDATE 202_attribution_models SET recompute_retry_at = 0
             WHERE user_id = ? AND recompute_requested_at IS NOT NULL AND recompute_retry_at > 0'
        );
        $conn->bind($stmt, 'i', [$userId]);
        $models = $conn->executeUpdate($stmt);

        return ['conversions' => $conversions, 'merges' => $merges, 'models' => $models];
    }
}
