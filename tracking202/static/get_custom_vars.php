<?php
declare(strict_types=1);
header('Content-Type: application/json');
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
$data = [];
$tracker_id_public = $db->real_escape_string((string)($_GET['t202id'] ?? ''));
$sql = "SELECT
		2cv.parameters
		FROM 202_trackers
		LEFT JOIN 202_ppc_accounts USING (ppc_account_id)
		LEFT JOIN (SELECT ppc_network_id, GROUP_CONCAT(parameter ORDER BY ppc_variable_id) AS parameters FROM 202_ppc_network_variables WHERE deleted = 0 GROUP BY ppc_network_id) AS 2cv USING (ppc_network_id)
		WHERE tracker_id_public = '".$tracker_id_public."'";
$result = $db->query($sql);
if ($result && $result->num_rows > 0) {
	$row = $result->fetch_assoc();
	// The source's variable names: none for a source that has none (its NULL
	// list answered [""]), as TrackerVariables reads them for the click.
	$data = array_column(\Prosper202\Click\TrackerVariables::pairs($row), 0);
}

echo json_encode($data, JSON_UNESCAPED_UNICODE);