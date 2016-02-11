<?php header('Content-type: application/javascript');
header('P3P: CP="Prosper202 does not have a P3P policy"');
include_once(substr(dirname( __FILE__ ), 0,-19) . '/202-config/connect2.php'); 
 
//lets find out if this is an advance or simple landing page, so we can include the appropriate script for each
$landing_page_id_public = $_GET['lpip'];
$mysql['landing_page_id_public'] = $db->real_escape_string($landing_page_id_public);
$tracker_sql = "SELECT  landing_page_type
				FROM      202_landing_pages
				WHERE   landing_page_id_public='".$mysql['landing_page_id_public']."'";
$tracker_row = memcache_mysql_fetch_assoc($db, $tracker_sql);       

if (!$tracker_row) { die(); }
if ($tracker_row['landing_page_type'] == 0) { 
	include_once(substr(dirname( __FILE__ ), 0,-19) .'/tracking202/static/record_simple.php'); die();
} elseif ($tracker_row['landing_page_type'] == 1){
	include_once(substr(dirname( __FILE__ ), 0,-19) .'/tracking202/static/record_adv.php'); die();
}