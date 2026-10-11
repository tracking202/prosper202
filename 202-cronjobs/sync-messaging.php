<?php

/**
 * Analytics Delivery Cron Job
 *
 * Delivers every active user's pending product-analytics events and changed
 * attributes (202_messaging_events, 202_messaging_attributes) to the portal's
 * POST /track, so what a page recorded reaches it even when nobody opens a
 * page afterwards. track.php delivers on every call (throttled), so this is
 * a booster rather than the primary path. Chat does not pass through the
 * install, so there is nothing else to sync.
 *
 * Run every few minutes via cron (every 5 minutes is a good cadence):
 *   /usr/bin/php /path/to/prosper202/202-cronjobs/sync-messaging.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../202-config/connect.php';

// Prevent overlapping runs.
$lockFile   = __DIR__ . '/messaging-sync.lock';
$maxLockAge = 600; // 10 minutes

if (file_exists($lockFile)) {
    if (time() - (int) filemtime($lockFile) < $maxLockAge) {
        echo "Messaging sync already in progress, exiting.\n";
        exit(0);
    }
    unlink($lockFile);
}
touch($lockFile);
register_shutdown_function(static function () use ($lockFile): void {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
});

try {
    echo 'Starting analytics delivery at ' . date('Y-m-d H:i:s') . "\n";

    $database = DB::getInstance();
    $db = $database->getConnection();

    // Active, non-deleted users only.
    $sql = "SELECT user_id FROM 202_users WHERE user_active = 1 AND user_deleted = 0";
    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException('failed to list users');
    }

    $counts = [
        MessagingService::SYNC_SENT => 0,
        MessagingService::SYNC_IDLE => 0,
        MessagingService::SYNC_FAILED => 0,
    ];
    while ($row = $result->fetch_assoc()) {
        $userId = (int) $row['user_id'];
        try {
            $service = MessagingService::forUser($userId);
        } catch (Throwable $e) {
            // One unreadable user is that user's failure, not the run's.
            error_log('Analytics delivery: user ' . $userId . ' could not be read: ' . $e->getMessage());
            $counts[MessagingService::SYNC_FAILED]++;
            continue;
        }
        if ($service === null) {
            continue;
        }

        // Force past the per-request throttle; the cron cadence is the throttle here.
        $outcome = $service->sync(true);
        $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
    }

    $summary = "{$counts[MessagingService::SYNC_SENT]} delivered, {$counts[MessagingService::SYNC_IDLE]} with nothing pending, {$counts[MessagingService::SYNC_FAILED]} failed";
    echo 'Analytics delivery completed at ' . date('Y-m-d H:i:s') . "\n";
    echo "Results: {$summary}\n";
    error_log("Analytics delivery completed: {$summary}");
    exit($counts[MessagingService::SYNC_FAILED] > 0 ? 1 : 0);
} catch (Throwable $e) {
    $msg = 'Analytics delivery failed: ' . $e->getMessage();
    echo $msg . "\n";
    error_log($msg);
    exit(1);
}
