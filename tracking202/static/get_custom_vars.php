<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
include_once(substr(dirname( __FILE__ ), 0,-19) . '/202-config/connect2.php');
$data = array();
$tracker_id_public = $db->real_escape_string($_GET['t202id']); 
$sql = "SELECT 
		2cv.parameters 
		FROM 202_trackers 
		LEFT JOIN 202_ppc_accounts USING (ppc_account_id)
		LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(parameter) AS parameters FROM 202_ppc_network_variables GROUP BY ppc_network_id) AS 2cv USING (ppc_network_id) 
		WHERE tracker_id_public = '".$tracker_id_public."'";
$result = $db->query($sql);
if ($result->num_rows > 0) {
	$row = $result->fetch_assoc();
	$parameters = explode(',', $row['parameters']);

	foreach ($parameters as $parameter) {
		$data[] = $parameter;
	}
}

echo json_encode($data, true);
?>