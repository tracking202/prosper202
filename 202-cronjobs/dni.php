<?php 
include_once(substr(dirname( __FILE__ ), 0,-13) . '/202-config/connect.php'); 

if (isset($_GET['hash']) && isset($_GET['dni'])) {
	$mysql['networkId'] = $db->real_escape_string($_GET['dni']);
	$sql = "SELECT apiKey, install_hash, type FROM 202_dni_networks JOIN 202_users USING (user_id) WHERE networkId = '".$mysql['networkId']."'"; 
	$results = $db->query($sql);
	if ($results->num_rows > 0) {
		$row = $results->fetch_assoc();
		
		if ($row['install_hash'] != $_GET['hash']) {
			die("Unautorized!");
		}

		if ($_GET['processed'] == 'false') {
			$data = array(
				'credentials' => array(
	                'install_hash' => $row['install_hash'],
	                'networkId' => $_GET['dni'],
	                'api_key' => $row['apiKey'],
	                'type' => $row['type'],
	                'host' => getDNIHost()
	            )
			);

			$curl = curl_init('http://my.tracking202.com/api/v2/dni/iron/offers/cache/all');
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
			curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, true));
			$response = curl_exec($curl);
		} else if ($_GET['processed'] == 'true') {
			$sql = "UPDATE 202_dni_networks SET processed = '1' WHERE networkId = '".$mysql['networkId']."' AND apiKey = '".$row['apiKey']."'";
			$results = $db->query($sql);
		}

	} else {
		die("Unautorized!");
	}
}

?>
