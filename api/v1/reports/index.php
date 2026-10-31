<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-15) . '/202-config.php'); 
// Goes on when the database is out of reach: it answers the outage in JSON.
$p202OutageFallback = true;
include_once(substr(__DIR__, 0,-15) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-15) . '/api/v1/functions.php');

header('Content-Type: application/json');
$data = [];

if ($_SERVER['REQUEST_METHOD'] === "GET") {
    if (!isset($db)) {
        // The outage in the status too: a client reads that before the body.
        http_response_code(503);
        $data = ['msg' => 'Database connection is unavailable', 'error' => true, 'status' => 503];
    } else {
        $data = getStats($db, $_GET);
    }
} else {
	$data = ['msg' => 'Not allowed request method', 'error' => true, 'status' => 405];
}

array_walk_recursive($data, function(&$val): void {
    if (is_string($val)) {
        $val = mb_convert_encoding($val, 'UTF-8', 'ISO-8859-1');
    }
});


echo legacy_api_json($data);
