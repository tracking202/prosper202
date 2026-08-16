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

// The check-then-insert window guard below is not atomic and 202_cronjobs has
// no unique key on (cronjob_type, cronjob_time), so two overlapping runs could
// both pass the check and rebuild the same bucket. Take an exclusive
// non-blocking file lock first; the loser exits instead of duplicating work.
$attrLockPath = sys_get_temp_dir() . '/p202-attribution-rebuild.lock';
$attrLockHandle = fopen($attrLockPath, 'c+');
if ($attrLockHandle === false) {
    fwrite(STDERR, "Unable to open attribution cron lock file.\n");
    exit(1);
}
if (!flock($attrLockHandle, LOCK_EX | LOCK_NB)) {
    fclose($attrLockHandle);
    fwrite(STDOUT, "Attribution cron is already running; skipping.\n");
    exit(0);
}
// Released implicitly when the process exits.
register_shutdown_function(static function () use ($attrLockHandle): void {
    flock($attrLockHandle, LOCK_UN);
    fclose($attrLockHandle);
});

$database = DB::getInstance();
$connection = $database?->getConnection();
if ($connection instanceof mysqli) {
    $checkStmt = $connection->prepare('SELECT 1 FROM 202_cronjobs WHERE cronjob_type = ? AND cronjob_time = ? LIMIT 1');
    if ($checkStmt) {
        $checkStmt->bind_param('si', $cronType, $cronBucket);
        $checkStmt->execute();
        $checkStmt->store_result();
        if ($checkStmt->num_rows > 0) {
            fwrite(STDOUT, "Attribution cron already processed this window; skipping.\n");
            $checkStmt->close();
            exit(0);
        }
        $checkStmt->close();

        $insertStmt = $connection->prepare('INSERT INTO 202_cronjobs SET cronjob_type = ?, cronjob_time = ?');
        if ($insertStmt) {
            $insertStmt->bind_param('si', $cronType, $cronBucket);
            $insertStmt->execute();
            $insertStmt->close();
        }

        $cleanupStmt = $connection->prepare('DELETE FROM 202_cronjobs WHERE cronjob_type = ? AND cronjob_time < ?');
        if ($cleanupStmt) {
            $cleanupStmt->bind_param('si', $cronType, $cronBucket);
            $cleanupStmt->execute();
            $cleanupStmt->close();
        }
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
