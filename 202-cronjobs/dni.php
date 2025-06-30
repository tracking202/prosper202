<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
	include_once(str_repeat("../", 1) . '202-config/connect.php');

	if (isset($_GET['hash']) && isset($_GET['dni'])) {
		$mysql['networkId'] = $db->real_escape_string((string)$_GET['dni']);
		$sql = "SELECT apiKey, install_hash, type FROM 202_dni_networks JOIN 202_users USING (user_id) WHERE networkId = '" . $mysql['networkId'] . "'";
		$results = $db->query($sql);
		if ($results && $results->num_rows > 0) {
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

				$curl = curl_init('https://my.tracking202.com/api/v2/dni/iron/offers/cache/all');
				curl_setopt($curl, CURLOPT_HEADER, false);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
				curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_NUMERIC_CHECK));
				$response = curl_exec($curl);
			} else if ($_GET['processed'] == 'true') {
				$sql = "UPDATE 202_dni_networks SET processed = '1' WHERE networkId = '" . $mysql['networkId'] . "' AND apiKey = '" . $row['apiKey'] . "'";
				$results = $db->query($sql);
			}
		} else {
			die("Unauthorized!");
		}
	}
} catch (Exception $e) {
	echo "Error: " . $e->getMessage();
	error_log("DNI Error: " . $e->getMessage());
}
