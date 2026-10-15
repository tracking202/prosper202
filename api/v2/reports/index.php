<?php
declare(strict_types=1);
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config.php'); 
// Goes on when the database is out of reach: it answers the outage in JSON.
$p202OutageFallback = true;
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect2.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/api/v2/functions.php');

header('Content-Type: application/json');
$data = [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($db)) {
        // The outage in the status too: a client reads that before the body.
        http_response_code(503);
        $data = ['msg' => 'Database connection is unavailable', 'error' => true, 'status' => 503];
    } else {
        $data = getAuth($db, $_GET);
        if (($data['msg'] ?? '') === 'Authorized') {
            $timezone = (string) ($data['user_timezone'] ?? 'UTC');
            $data = runReports($db, $_GET, (int) ($data['user_id'] ?? 0), $timezone, 'v2');
        }
    }

} else {
	$data = ['msg' => 'Not allowed request method', 'error' => true, 'status' => 405];
}

echo legacy_api_json($data);
