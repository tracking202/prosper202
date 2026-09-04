#!/usr/bin/env php
<?php

declare(strict_types=1);

use Prosper202\Attribution\AttributionServiceFactory;

require_once __DIR__ . '/../202-config/connect.php';

$options = getopt('', ['user::', 'start::', 'end::']);
$endTime = isset($options['end']) ? (int) $options['end'] : time();
$startTime = isset($options['start']) ? (int) $options['start'] : $endTime - 86400;

if ($startTime >= $endTime) {
    fwrite(STDERR, "Start timestamp must be earlier than end timestamp.\n");
    exit(1);
}

$userIds = [];
if (isset($options['user'])) {
    $userIds[] = (int) $options['user'];
} else {
    $db = DB::getInstance();
    $conn = $db?->getConnection();
    if (!$conn) {
        fwrite(STDERR, "Unable to obtain database connection.\n");
        exit(1);
    }

    $result = $conn->query('SELECT user_id FROM 202_users WHERE user_deleted = 0');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $userIds[] = (int) $row['user_id'];
        }
        $result->close();
    }
}

if ($userIds === []) {
    fwrite(STDOUT, "No users found for attribution processing.\n");
    exit(0);
}

$cronBucket = (int) ($endTime - ($endTime % 3600));
$cronType = 'attr';

$database = DB::getInstance();
$connection = $database?->getConnection();
if ($connection instanceof mysqli) {
    $checkStmt = $connection->prepare('SELECT 1 FROM 202_cronjobs WHERE cronjob_type = ? AND cronjob_time = ? LIMIT 1');
    if (!$checkStmt) {
        // Skipping the check silently would rebuild a window that may already
        // have been done — the same outcome the execute() guard below exists
        // to prevent, so it fails the same way.
        fwrite(STDERR, 'Failed to prepare the attribution cron marker check: ' . $connection->error . "\n");
        exit(1);
    }
    $checkStmt->bind_param('si', $cronType, $cronBucket);
    // Unchecked, a failed execute leaves num_rows at 0, which reads as
    // "this window has not been processed" — so the job would rebuild
    // attribution for a window it had already done.
    if (!$checkStmt->execute()) {
        $error = $checkStmt->error;
        $checkStmt->close();
        fwrite(STDERR, 'Failed to check the attribution cron marker: ' . $error . "\n");
        exit(1);
    }
    // store_result() has the same failure mode as execute(): a false
    // return leaves num_rows at 0, which reads as "not yet processed".
    if (!$checkStmt->store_result()) {
        $error = $checkStmt->error;
        $checkStmt->close();
        fwrite(STDERR, 'Failed to buffer the attribution cron marker check: ' . $error . "\n");
        exit(1);
    }
    if ($checkStmt->num_rows > 0) {
        fwrite(STDOUT, "Attribution cron already processed this window; skipping.\n");
        $checkStmt->close();
        exit(0);
    }
    $checkStmt->close();

    $insertStmt = $connection->prepare('INSERT INTO 202_cronjobs SET cronjob_type = ?, cronjob_time = ?');
    if (!$insertStmt) {
        // Without the marker the next run reprocesses this window.
        fwrite(STDERR, 'Failed to prepare the attribution cron marker insert: ' . $connection->error . "\n");
        exit(1);
    }
    $insertStmt->bind_param('si', $cronType, $cronBucket);
    // Without the marker the next run reprocesses this same window.
    if (!$insertStmt->execute()) {
        $error = $insertStmt->error;
        $insertStmt->close();
        fwrite(STDERR, 'Failed to record the attribution cron marker: ' . $error . "\n");
        exit(1);
    }
    $insertStmt->close();

    $cleanupStmt = $connection->prepare('DELETE FROM 202_cronjobs WHERE cronjob_type = ? AND cronjob_time < ?');
    if ($cleanupStmt) {
        $cleanupStmt->bind_param('si', $cronType, $cronBucket);
        // Pruning old markers is housekeeping: a failure leaves stale
        // rows but does not affect this run, so warn and carry on.
        if (!$cleanupStmt->execute()) {
            fwrite(STDERR, 'Warning: could not prune old attribution cron markers: ' . $cleanupStmt->error . "\n");
        }
        $cleanupStmt->close();
    }
}

$jobRunner = AttributionServiceFactory::createJobRunner();
$errors = [];

foreach ($userIds as $userId) {
    try {
        $jobRunner->runForUser($userId, $startTime, $endTime);
    } catch (Throwable $throwable) {
        $errors[] = sprintf('User %d: %s', $userId, $throwable->getMessage());
    }
}

if ($connection instanceof mysqli) {
    $timestamp = time();
    $connection->query('REPLACE INTO 202_cronjob_logs (id, last_execution_time) VALUES (2, ' . $timestamp . ')');
}

if ($errors !== []) {
    fwrite(STDERR, "Attribution recalculation completed with errors:\n" . implode("\n", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Attribution recalculation complete for %d user(s) covering %s to %s.\n",
    count($userIds),
    date('c', $startTime),
    date('c', $endTime)
));
