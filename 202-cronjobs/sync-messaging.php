<?php

/**
 * Analytics Delivery Cron Job
 *
 * Delivers pending product-analytics events and changed attributes
 * (202_messaging_events, 202_messaging_attributes) to the portal's POST
 * /track for every user with something pending: MessagingService::
 * deliverPending(), which the hourly tier of 202-cronjobs/index.php also
 * runs on every default deployment. Run this file on its own only for a
 * tighter cadence; not more often than every 5 minutes, because each failed
 * delivery spends one of an event's five attempts.
 *
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

    $counts = MessagingService::deliverPending(DB::getInstance()->getConnection());

    $summary = "{$counts['sent']} delivered, {$counts['more']} with more pending, {$counts['idle']} with nothing pending, "
        . "{$counts['failed']} failed, {$counts['stopped']} not tried after repeated failures";
    echo 'Analytics delivery completed at ' . date('Y-m-d H:i:s') . "\n";
    echo "Results: {$summary}\n";
    error_log("Analytics delivery completed: {$summary}");
    exit($counts['failed'] > 0 ? 1 : 0);
} catch (Throwable $e) {
    $msg = 'Analytics delivery failed: ' . $e->getMessage();
    echo $msg . "\n";
    error_log($msg);
    exit(1);
}
