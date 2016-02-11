<?php
header("content-type: image/gif"); 
header('Content-Length: 43');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Expires: Sun, 03 Feb 2008 05:00:00 GMT'); // Date in the past
header("Pragma: no-cache");
header('P3P: CP="Prosper202 does not have a P3P policy"');
echo base64_decode("R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==");

include_once(substr(dirname( __FILE__ ), 0,-19) . '/202-config/connect2.php'); 

$t202id = $_GET['t202id'];
if (!is_numeric($t202id)) die();

$mysql['tracker_id_public'] = $db->real_escape_string($t202id);
$time = time();
$tracker_sql = "SELECT aff_campaign_id,
					   text_ad_id,
					   ppc_account_id,
					   landing_page_id
				FROM 202_trackers 
                WHERE tracker_id_public = '".$mysql['tracker_id_public']."'";
$tracker_row = memcache_mysql_fetch_assoc($db, $tracker_sql);

$sql = "INSERT INTO 202_clicks_impressions 
 		SET aff_campaign_id = '".$tracker_row['aff_campaign_id']."',
 		landing_page_id = '".$tracker_row['landing_page_id']."',
 		ppc_account_id = '".$tracker_row['ppc_account_id']."',
 		text_ad_id = '".$tracker_row['text_ad_id']."',
 		impression_time = '".$time."'";
$db->query($sql);
$ipx_id = $db->insert_id;	

setcookie("p202_ipx", $ipx_id, $time + (10 * 365 * 24 * 60 * 60), '/', $_SERVER['SERVER_NAME']);

?>