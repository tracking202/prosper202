<?php
include_once(str_repeat("../", 2).'202-config/connect.php');

AUTH::require_user();

$mysql['prosper_alert_id'] = $db->real_escape_string($_POST['prosper_alert_id']);
$alert_sql = "INSERT INTO 202_alerts SET prosper_alert_seen='1', prosper_alert_id='{$mysql['prosper_alert_id']}'";
$alert_sql = _mysqli_query($alert_sql);