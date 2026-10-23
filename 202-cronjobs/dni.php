<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
try {
	require_once __DIR__ . '/../202-config/connect.php';

	if (isset($_GET['hash']) && isset($_GET['dni'])) {
		$mysql['networkId'] = $db->real_escape_string((string)$_GET['dni']);
		$sql = "SELECT apiKey, install_hash, type FROM 202_dni_networks JOIN 202_users USING (user_id) WHERE networkId = '" . $mysql['networkId'] . "'";
        $results = $db->query($sql);
        // A failed read is the database error page (a 500), not "Unauthorized!".
        if ($results === false) {
            record_mysql_error($db, $sql);
        }
        $row = p202FetchRow($results, $sql);
        if ($row !== null) {

            // The registering account's install hash, constant-time, and never
            // an empty one: hash_equals('', '') is true (InstallHash).
            if (!\Prosper202\User\InstallHash::matches($row['install_hash'] ?? null, $_GET['hash'] ?? null)) {
                die("Unauthorized!");
            }

            if ($_GET['processed'] == 'false') {
                $data = [
                    'credentials' => [
                        'install_hash' => $row['install_hash'],
                        'networkId' => $_GET['dni'],
                        'api_key' => $row['apiKey'],
                        'type' => $row['type'],
                        'host' => getDNIHost()
                    ]
                ];

                $curl = curl_init('https://my.tracking202.com/api/v2/dni/iron/offers/cache/all');
                curl_setopt($curl, CURLOPT_HEADER, false);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, false);
                curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-type: application/json"]);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_NUMERIC_CHECK));
                $response = curl_exec($curl);
            } else if ($_GET['processed'] == 'true') {
                // The hosted service's callback names the network by its id
                // and key, the pair it was sent above: it marks that network
                // of whichever account registered it, by design.
                $sql = "UPDATE 202_dni_networks SET processed = '1' WHERE networkId = '" . $mysql['networkId'] . "' AND apiKey = '" . $row['apiKey'] . "'";
                if (!$db->query($sql)) {
                    record_mysql_error($db, $sql);
                }
            }
        } else {
            die("Unauthorized!");
        }
	}
} catch (Exception $e) {
    // A failure answers 500, not a 200 with "Error:" in the body.
    if (!headers_sent()) {
        http_response_code(500);
    }
	echo "Error: " . $e->getMessage();
	error_log("DNI Error: " . $e->getMessage());
}
