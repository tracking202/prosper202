<?php
declare(strict_types=1);
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user();

// Require a valid session token for this state-changing request.
if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
    die();
}

$mysql['prosper_alert_id'] = $db->real_escape_string((string)$_POST['prosper_alert_id']);
$alert_sql = "INSERT INTO 202_alerts SET prosper_alert_seen='1', prosper_alert_id='{$mysql['prosper_alert_id']}'";
$alert_result = _mysqli_query($alert_sql, $db);