<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	if (isset($_POST['clickserver_id']) && $_POST['clickserver_id']) {
		$api_key = base64_decode((string) $_POST['api_key']);
		$clickserverId = base64_encode((string) $_POST['clickserver_id']);
		if (clickserver_api_domain_act_deact($api_key, $clickserverId, $_POST['method'])) {
			$data = true;
			echo $data;
		}
	}
}

function clickserver_api_domain_act_deact($key, $csid, $method){
	//Initiate curl
	$ch = curl_init();
	// Disable SSL verification
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/'.$method.'/?apiKey='.$key.'&clickserverId='.$csid);
	// Execute
	$result=curl_exec($ch);

	$data = json_decode($result, true);

	if ($method == 'activate') {
		$success = $data['isActivationSuccess'];
	} else {
		$success = $data['isDeactivationSuccess'];
	}
		if ($data['isValidKey'] != 'true' || $success != 'true') {
			return false;
			die();
		}

	return true;

	curl_close($ch);
}

function clickserver_api_domain_list($key){
	
	//Initiate curl
	$ch = curl_init();
	// Disable SSL verification
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/list/?apiKey='.$key);
	// Execute
	$result=curl_exec($ch);

	$data = json_decode($result, true);

	return $data;

	curl_close($ch);
}

function clickserver_api_license($key){

	//Initiate curl
	$ch = curl_init();
	// Disable SSL verification
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/license/?apiKey='.$key);
	// Execute
	$result=curl_exec($ch);

	$data = json_decode($result, true);

	return $data;

	curl_close($ch);
}

?>