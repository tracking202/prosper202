<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect2.php'); 

//get the aff_camapaign_id
$mysql['aff_campaign_id_public'] = mysql_real_escape_string($_GET['acip']);

$aff_campaign_sql = "SELECT aff_campaign_id FROM 202_aff_campaigns WHERE aff_campaign_id_public='".$mysql['aff_campaign_id_public']."'";
$aff_campaign_row =  memcache_mysql_fetch_assoc($aff_campaign_sql);

if (!$aff_campaign_row) { die(); }

$mysql['aff_campaign_id'] = mysql_real_escape_string($aff_campaign_row['aff_campaign_id']);

if (!$_GET['subid']) { die(); }

$mysql['click_id'] = mysql_real_escape_string($_GET['subid']);

//ok now update and fire the pixel tracking
$click_sql = "UPDATE 202_clicks SET click_lead='1', click_filtered='0'  WHERE click_id='".$mysql['click_id']."' AND aff_campaign_id='".$mysql['aff_campaign_id']."'";
delay_sql($click_sql);

$click_sql = "UPDATE 202_clicks_spy SET click_lead='1', click_filtered='0' WHERE click_id='".$mysql['click_id']."' AND aff_campaign_id='".$mysql['aff_campaign_id']."'";
delay_sql($click_sql);