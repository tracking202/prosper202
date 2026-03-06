<?php
declare(strict_types=1);
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config.php'); 
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect2.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/api/v1/functions.php');

header('Content-Type: application/json');
$data = [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($db)) {
        $data = ['msg' => 'Database connection is unavailable', 'error' => true, 'status' => 500];
    } else {
        $data = getAuth($db, $_GET);
        $variables = (isset($variables) && is_array($variables)) ? $variables : [];
        $key_row = (isset($key_row) && is_array($key_row)) ? $key_row : ['user_id' => 0];
        $user_row = (isset($user_row) && is_array($user_row)) ? $user_row : ['user_timezone' => 'UTC'];
        runReports($db, $variables, (int) $key_row['user_id'], (string) $user_row['user_timezone']);
    }

} else {
	$data = ['msg' => 'Not allowed request method', 'error' => true, 'status' => 405];
}

$json = str_replace('\\/', '/', json_encode($data));

print_r(pretty_json($json));
?>
