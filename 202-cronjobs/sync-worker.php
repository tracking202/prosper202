<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/202-config.php';
require_once dirname(__DIR__) . '/202-config/version.php';

use Api\V3\Bootstrap;
use Api\V3\Controllers\SyncController;

$limit = 10;
if (isset($argv[1]) && is_numeric($argv[1])) {
    $limit = max(1, min(100, (int)$argv[1]));
}

try {
    Bootstrap::init();
    $db = Bootstrap::db();
    // This job bootstraps the API rather than 202-config/connect.php, so it
    // makes connect.php's check itself: never run against a schema the code
    // is not (tests/Cron/CronEntryPointsFailLoudlyTest).
    $stale = \Prosper202\Database\SchemaVersion::mismatch($db, PROSPER202_VERSION);
    if ($stale !== null) {
        fwrite(STDERR, 'Prosper202 (sync-worker.php): ' . $stale . PHP_EOL);
        exit(1);
    }
    $controller = new SyncController($db, 0);
    $result = $controller->runWorker(['limit' => $limit]);

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'sync-worker failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
