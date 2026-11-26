<?php

declare(strict_types=1);

// A process that runs one sync job and dies in it, as SyncJobRunLostTest asks:
// run-and-die.php <state dir> <job id> <kill|time|memory|exit>

use Api\V3\Controllers\SyncController;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncProfileKeys;
use Tests\Support\DyingSyncEngine;

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $dir, $jobId, $how] = $argv;
ini_set('error_log', '/dev/null');
$store = new ServerStateStore($dir);
$db = (new ReflectionClass(mysqli::class))->newInstanceWithoutConstructor();
$controller = new SyncController(
    $db,
    42,
    $store,
    new DyingSyncEngine($store, $how),
    SyncProfileKeys::withKey(str_repeat('k', 32))
);
$controller->runJob($jobId);
fwrite(STDERR, "the run finished, which it was not meant to\n");
exit(9);
