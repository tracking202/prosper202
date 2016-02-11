<?php include_once(substr(dirname( __FILE__ ), 0,-19) . '/202-config/connect2.php'); 
include_once(substr(dirname( __FILE__ ), 0,-19) . '/202-config/class-dataengine-slim.php');
//get the aff_camapaign_id
$mysql['aff_campaign_id_public'] = $db->real_escape_string($_GET['acip']);

$aff_campaign_sql = "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public='".$mysql['aff_campaign_id_public']."'";
$aff_campaign_row =  memcache_mysql_fetch_assoc($db, $aff_campaign_sql);

if (!$aff_campaign_row) { die(); }

$mysql['aff_campaign_id'] = $db->real_escape_string($aff_campaign_row['aff_campaign_id']);

if (!$_GET['subid']) { die(); }

$mysql['click_id'] = $db->real_escape_string($_GET['subid']);

$cpa_sql = "SELECT 202_cpa_trackers.tracker_id_public, 202_trackers.click_cpa FROM 202_cpa_trackers LEFT JOIN 202_trackers USING (tracker_id_public) WHERE click_id = '".$mysql['click_id']."'";
$cpa_result = $db->query($cpa_sql);
$cpa_row = $cpa_result->fetch_assoc();

$mysql['click_cpa'] = $db->real_escape_string($cpa_row['click_cpa']);
	
if ($mysql['click_cpa']) {
	$sql_set = "click_cpc='".$mysql['click_cpa']."', click_lead='1', click_filtered='0'";
} else {
	$sql_set = "click_lead='1', click_filtered='0'";
}

//ok now update and fire the pixel tracking
$click_sql = "UPDATE 202_clicks SET ".$sql_set." WHERE click_id='".$mysql['click_id']."' AND aff_campaign_id='".$mysql['aff_campaign_id']."'";
$db->query($click_sql);

$click_sql = "UPDATE 202_clicks_spy SET ".$sql_set." WHERE click_id='".$mysql['click_id']."' AND aff_campaign_id='".$mysql['aff_campaign_id']."'";
$db->query($click_sql);

//set dirty hour
$de = new DataEngine();
$data=($de->setDirtyHour($mysql['click_id']));