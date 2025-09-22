<?php
declare(strict_types=1);
include_once(substr(__DIR__, 0,-15) . '/202-config.php'); 
include_once(substr(__DIR__, 0,-15) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-15) . '/api/v1/functions.php');

header('Content-Type: application/json');
$data = [];

if ($_SERVER['REQUEST_METHOD'] == "GET") {
	$data = getStats($db, $_GET);
} else {
	$data = ['msg' => 'Not allowed request method', 'error' => true, 'status' => 405];
}

array_walk_recursive($data, function(&$val): void {
    $val = mb_convert_encoding($val, 'UTF-8', 'ISO-8859-1');
});


$json = str_replace('\\/', '/', json_encode($data));

print_r(pretty_json($json));
?>