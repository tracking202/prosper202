<?php
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config.php'); 
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect2.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/api/v2/functions.php');

header('Content-Type: application/json');
$data = array();

if ($_SERVER['REQUEST_METHOD'] == "GET") {
			$data = getAuth($db, $_GET);
			if( $data['msg']=="Authorized"){
			    showCategories($db, $_GET);
			    die();
			}

} else {
	$data = array('msg' => 'Not allowed request method', 'error' => true, 'status' => 405);
}

if(isset($data)){

$json = str_replace('\\/', '/', json_encode($data));

print_r(pretty_json($json));
}
?>