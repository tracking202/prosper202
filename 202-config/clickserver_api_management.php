<?php
declare(strict_types=1);
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	if (isset($_POST['clickserver_id']) && $_POST['clickserver_id']) {
		// Authenticate the request and validate the session token before
		// performing this state-changing operation.
		include_once(__DIR__ . '/connect.php');
		include_once(__DIR__ . '/functions-auth.php');

		AUTH::require_user();

		if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
			http_response_code(403);
			echo false;
			return;
		}

		$api_key = base64_decode((string) $_POST['api_key']);
		$clickserverId = base64_encode((string) $_POST['clickserver_id']);
		if (clickserver_api_domain_act_deact($api_key, $clickserverId, (string) $_POST['method'])) {
			$data = true;
			echo $data;
		}
	}
}

function clickserver_api_domain_act_deact($key, $csid, $method){
	// Restrict to the known endpoints before building the request path.
	if (!in_array($method, ['activate', 'deactivate'], true)) {
		return false;
	}
	//Initiate curl
	$ch = curl_init();
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/'.$method.'/?apiKey='.rawurlencode($key).'&clickserverId='.rawurlencode($csid));
	// Execute
	$result=curl_exec($ch);

	$data = json_decode($result, true);

	if ($method == 'activate') {
		$success = $data['isActivationSuccess'];
	} else {
		$success = $data['isDeactivationSuccess'];
	}
			if ($data['isValidKey'] != 'true' || $success != 'true') {
				curl_close($ch);
				return false;
			}

		curl_close($ch);
		return true;
}

function clickserver_api_domain_list($key){

	//Initiate curl
	$ch = curl_init();
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/list/?apiKey='.$key);
	// Execute
	$result=curl_exec($ch);

	$data = json_decode($result, true);

	curl_close($ch);
	return $data;
}

function clickserver_api_license($key){

	//Initiate curl
	$ch = curl_init();
	// Will return the response, if false it print the response
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	// Set the url
	curl_setopt($ch, CURLOPT_URL, 'https://my.tracking202.com/api/v1/license/?apiKey='.$key);
	// Execute
	$result=curl_exec($ch);

	$data = json_decode($result, true);

	curl_close($ch);
	return $data;
}
