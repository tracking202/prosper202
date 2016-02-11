<?php 

header("Content-type: application/octet-stream");

# replace excelfile.xls with whatever you want the filename to default to
header("Content-Disposition: attachment; filename=T202_devices_".time().".xls");
header("Pragma: no-cache");
header("Expires: -1");

include_once(substr(dirname( __FILE__ ), 0,-20) . '/202-config/connect.php');
include_once(substr(dirname( __FILE__ ), 0,-20) . '/202-config/class-dataengine.php'); 

AUTH::require_user();

$time = grab_timeframe();
$mysql['to'] = $db->real_escape_string($time['to']);
$mysql['from'] = $db->real_escape_string($time['from']);


$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
$user_sql = "SELECT user_pref_breakdown, user_pref_show, user_cpc_or_cpv FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
$user_result = _mysqli_query($user_sql, $dbGlobalLink); //($user_sql);
$user_row = $user_result->fetch_assoc();
$breakdown = $user_row['user_pref_breakdown'];

$de = new DataEngine();
$de->setDownload(); //enable downloads query modification. removes the LIMIT filter

$data=($de->getReportData('device', $mysql['from'], $mysql['to'],$cpv));
	
$dr= new DisplayData();
$dr->downloadReport('device', $data, $de->foundRows());

$de->setDisplay(); //disable downloads query modification
?>
