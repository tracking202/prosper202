<?php

/**
 * Analytics Delivery Cron Job
 *
 * Delivers pending product-analytics events and changed attributes
 * (202_messaging_events, 202_messaging_attributes) to the portal's POST
 * /track for every user with something pending: MessagingService::
 * deliverPendingExclusive(), which the hourly tier of 202-cronjobs/index.php
 * also runs on every default deployment, under the same lock
 * (Prosper202\Cron\CronLock on 202-cronjobs/analytics-delivery.lock): while
 * one delivers, the other does nothing. Run this file on its own only for a
 * tighter cadence; not more often than every 5 minutes, because each failed
 * delivery spends one of an event's five attempts.
 *
 *   /usr/bin/php /path/to/prosper202/202-cronjobs/sync-messaging.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../202-config/connect.php';

try {
    echo 'Starting analytics delivery at ' . date('Y-m-d H:i:s') . "\n";

    $lockPath = \Prosper202\Cron\CronLock::path(\Prosper202\Cron\CronLock::ANALYTICS_DELIVERY_FILE);
    $counts = MessagingService::deliverPendingExclusive(DB::getInstance()->getConnection(), $lockPath);
    if ($counts === null) {
        error_log('Analytics delivery already running: another run holds ' . $lockPath . '; this run did nothing.');
        exit("Analytics delivery already running\n");
    }

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
