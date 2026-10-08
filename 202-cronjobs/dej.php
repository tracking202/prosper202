<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
    require_once __DIR__ . '/../202-config/connect.php';
    require_once __DIR__ . '/../202-config/class-dataengine.php';

    set_time_limit(0);

    $snippet = "";
    $start = isset($_GET['s']) ? (int)$_GET['s'] : time() - 3600;
    //$end =$_GET['e'];

    $de = new DataEngine();
    $de->getSummary($start, $start + 3599, $snippet, 1, true);
} catch (\Throwable $e) {
    // process_dataengine_job.php marks the hour processed when every call
    // answers 200, so a rollup that failed answered 200 here and its hour
    // was never rolled up again (measured with the write refused: 200,
    // "Error: dataengine query failed"). A failure is a 500, and the job
    // leaves the hour for its next run.
    http_response_code(500);
    echo "Error: " . $e->getMessage();
    error_log("DEJ Error: " . $e->getMessage());
}
