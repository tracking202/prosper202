<?php
declare(strict_types=1);
include_once(substr(dirname( __FILE__ ), 0,-21) . '/202-config/connect2.php');
include_once(substr(dirname( __FILE__ ), 0,-21) . '/202-config/class-dataengine-slim.php');

$mysql['click_id_public'] = $db->real_escape_string((string)$_GET['pci']);

$click_sql = "
	SELECT
		202_clicks.click_id,
		202_clicks.aff_campaign_id,
		click_cloaking,
		click_cloaking_site_url_id,
		click_redirect_site_url_id
	FROM
		202_clicks 
		LEFT JOIN 202_clicks_record USING (click_id) 
		LEFT JOIN 202_clicks_site   USING (click_id)
	WHERE
		click_id_public='".$mysql['click_id_public']."'
";
$click_row = memcache_mysql_fetch_assoc($db, $click_sql);


$click_id = $click_row['click_id'];
$aff_campaign_id = $click_row['aff_campaign_id'];
$mysql['click_id'] = $db->real_escape_string($click_id);
$mysql['aff_campaign_id'] = $db->real_escape_string($aff_campaign_id);
$mysql['click_out'] = '1';

$click_sql = "UPDATE    202_clicks_record
			  SET       click_out='".$mysql['click_out']."'
			  WHERE     click_id='".$mysql['click_id']."'";
$click_result = $db->query($click_sql) or record_mysql_error($db, $click_sql);


//see if cloaking was turned on
if ($click_row['click_cloaking'] == 1) { 
	$cloaking_on = true;
	$mysql['site_url_id'] = $db->real_escape_string($click_row['click_cloaking_site_url_id']);
	$site_url_sql = "SELECT site_url_address FROM 202_site_urls WHERE site_url_id='".$mysql['site_url_id']."' limit 1";
	$site_url_row = memcache_mysql_fetch_assoc($db, $site_url_sql);
	$cloaking_site_url = $site_url_row['site_url_address'];
} else {
	$cloaking_on = false;
	$mysql['site_url_id'] = $db->real_escape_string($click_row['click_redirect_site_url_id']);
	$site_url_sql = "SELECT site_url_address FROM 202_site_urls WHERE site_url_id='".$mysql['site_url_id']."' limit 1";
	$site_url_row = memcache_mysql_fetch_assoc($db, $site_url_sql);
	$redirect_site_url = $site_url_row['site_url_address'];  	
}


//set the cookie
setClickIdCookie($mysql['click_id'],$mysql['aff_campaign_id']);

//set dirty hour
$de = new DataEngine();
$data=($de->setDirtyHour($mysql['click_id']));
	
//now we've updated, lets redirect
if ($cloaking_on == true) {
	//if cloaked, redirect them to the cloaked site. 
	header ('location: '.$cloaking_site_url);    
} else {
	header ('location: '.$redirect_site_url);        
}

