<?php

/**
 * Messaging Sync Cron Job
 *
 * Proactively syncs the Intercom-style messenger for active users so that
 * broadcasts and support replies arrive (and queued outbound messages/events are
 * delivered) even when a user does not currently have the dashboard open.
 *
 * The widget itself also syncs on every poll (throttled), so this cron is a
 * booster rather than the primary path.
 *
 * Run every few minutes via cron (every 5 minutes is a good cadence):
 *   /usr/bin/php /path/to/prosper202/202-cronjobs/sync-messaging.php
 */

declare(strict_types=1);

include_once(str_repeat('../', 1) . '202-config/connect.php');

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
    echo 'Starting messaging sync at ' . date('Y-m-d H:i:s') . "\n";

    $database = DB::getInstance();
    $db = $database->getConnection();

    // Active, non-deleted users only.
    $sql = "SELECT user_id FROM 202_users WHERE user_active = 1 AND user_deleted = 0";
    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException('failed to list users');
    }

    $synced = 0;
    $failed = 0;
    while ($row = $result->fetch_assoc()) {
        $userId  = (int) $row['user_id'];
        $service = MessagingService::forUser($userId);
        if ($service === null) {
            continue;
        }

        // Force past the per-request throttle; the cron cadence is the throttle here.
        if ($service->sync(true)) {
            $synced++;
        } else {
            $failed++;
        }
    }

    echo "Messaging sync completed at " . date('Y-m-d H:i:s') . "\n";
    echo "Results: {$synced} synced, {$failed} with no successful pull\n";
    error_log("Messaging sync completed: {$synced} synced, {$failed} no-pull");
    exit(0);
} catch (Throwable $e) {
    $msg = 'Messaging sync failed: ' . $e->getMessage();
    echo $msg . "\n";
    error_log($msg);
    exit(1);
}
