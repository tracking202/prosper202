<?php

declare(strict_types=1);

namespace Prosper202\Attribution;

use Prosper202\Database\Connection;

/**
 * "Try again now" for one account: its failed conversions, identity merges
 * and model recomputes are made due at once, as the worker's --retry-now
 * does for the whole install. The worker's next run takes them.
 */
final class QueueRetry
{
    private function __construct()
    {
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
