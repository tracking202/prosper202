<?php

declare(strict_types=1);

// The sync worker's load chain, minus mysql-error-args.php, with an engine that stops
// on a database error: run-worker-engine-fails.php <state dir> [with-error-args]

use Api\V3\Controllers\SyncController;
use Api\V3\Support\ServerStateStore;
use Api\V3\Support\SyncProfileKeys;
use Tests\Support\KeyCapturingSyncEngine;

// A checkout served as the web root serves tests/ too (TestScriptsAreNotServedTest).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
if (($argv[2] ?? '') === 'with-error-args') {
    require $root . '/202-config/mysql-error-args.php';
}
require $root . '/202-config/functions-shared.php';

$store = new ServerStateStore($argv[1]);
$db = (new ReflectionClass(mysqli::class))->newInstanceWithoutConstructor();
$controller = new SyncController(
    $db,
    42,
    $store,
    new KeyCapturingSyncEngine($store, new mysqli_sql_exception("Duplicate entry 'x' for key 'k'", 1062)),
    SyncProfileKeys::withKey(str_repeat('k', 32))
);
echo json_encode($controller->runWorker(['limit' => 5])) . PHP_EOL;
exit(0);
