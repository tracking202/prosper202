<? header('Content-type: application/javascript');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 
 
//lets find out if this is an advance or simple landing page, so we can include the appropriate script for each
$landing_page_id_public = $_GET['lpip'];
$mysql['landing_page_id_public'] = mysql_real_escape_string($landing_page_id_public);
$tracker_sql = "SELECT  landing_page_type
				FROM      202_landing_pages
				WHERE   landing_page_id_public='".$mysql['landing_page_id_public']."'";
$tracker_row = memcache_mysql_fetch_assoc($tracker_sql);       

if (!$tracker_row) { die(); }

if ($tracker_row['landing_page_type'] == 0) { 
	include_once($_SERVER['DOCUMENT_ROOT'] .'/tracking202/static/record_simple.php'); die();
} elseif ($tracker_row['landing_page_type'] == 1){
	include_once($_SERVER['DOCUMENT_ROOT'] .'/tracking202/static/record_adv.php'); die();
}