<?php
declare(strict_types=1);

use Prosper202\Attribution\Repository\Mysql\ConversionJourneyRepository;
header('P3P: CP="Prosper202 does not have a P3P policy"');
include_once(substr(__DIR__, 0,-19) . '/202-config/connect2.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-snoopy.php');
include_once(substr(__DIR__, 0,-19) . '/202-config/class-dataengine-slim.php');

//get the aff_camapaign_id
$mysql['user_id'] = 1;
$mysql['use_pixel_payout'] = 0;
$mysql['cid'] = 0;
$mysql['click_id'] = 0;

//first grab the subid
if(array_key_exists('subid',$_GET) && is_numeric($_GET['subid'])) {
	$mysql['click_id']= $db->real_escape_string((string)$_GET['subid']);
} elseif(array_key_exists('sid',$_GET) && is_numeric($_GET['sid'])) {
	$mysql['click_id']= $db->real_escape_string((string)$_GET['sid']);
} else {
	header('HTTP/1.1 404 Not Found', true, 404);
	header('Content-Type: application/json');
	$response = ['error' => true, 'code' => 404, 'msg' => 'SubID not found'];
	print_r(json_encode($response));
	die();
}

if (!$mysql['click_id']) {
	//ok grab the last click from this ip_id
	$mysql['ip_address'] = $db->real_escape_string($_SERVER['REMOTE_ADDR']);
	$daysago = time() - 2592000; // 30 days ago
	$click_sql1 = "	SELECT 	202_clicks.click_id
					FROM 		202_clicks
					LEFT JOIN	202_clicks_advance USING (click_id)
					LEFT JOIN 	202_ips USING (ip_id) 
					WHERE 	202_ips.ip_address='".$mysql['ip_address']."'
					AND		202_clicks.user_id='".$mysql['user_id']."'  
					AND		202_clicks.click_time >= '".$daysago."'
					ORDER BY 	202_clicks.click_id DESC 
					LIMIT 		1";

	$click_result1 = $db->query($click_sql1) or record_mysql_error($click_sql1);
	$click_row1 = $click_result1->fetch_assoc();

	$mysql['click_id'] = $db->real_escape_string($click_row1['click_id']);
	$mysql['ppc_account_id'] = $db->real_escape_string($click_row1['ppc_account_id']);

} else {
	$click_sql1 = "	SELECT 	ppc_account_id
					FROM 	202_clicks
					WHERE 	click_id='".$mysql['click_id']."'";
	$click_result1 = $db->query($click_sql1) or record_mysql_error($click_sql1);
	$click_row1 = $click_result1->fetch_assoc();
	$mysql['ppc_account_id'] = $db->real_escape_string($click_row1['ppc_account_id']);				
}

if(!$mysql['click_id']){
    header('HTTP/1.1 404 Not Found', true, 404);
	header('Content-Type: application/json');
	$response = ['error' => true, 'code' => 404, 'msg' => 'SubID not found'];
	print_r(json_encode($response));
	die();
}



    $site_urls=" LEFT JOIN `202_clicks_site` AS 2cs ON (2c.click_id=2cs.click_id)
                     LEFT JOIN `202_site_urls` AS 2su ON (2cs.click_referer_site_url_id=2su.site_url_id) ";

//get c1-c4 values etc
$cvar_sql ="
SELECT 
	2cid.click_id,
	2c.user_id,
	2c.aff_campaign_id,
	2c.click_payout,
	2c.click_cpc,
	2c.click_lead,
	2c.click_time,
	2c1.c1,
	2c2.c2,
	2c3.c3,
	2c4.c4,
	2kw.keyword,
	2g.gclid,
	2us.utm_source,
	2um.utm_medium,
	2uca.utm_campaign,
	2ut.utm_term,
	2uco.utm_content,
	2trc.click_cpa,
    2su.site_url_address
FROM `202_clicks_tracking` AS 2cid
LEFT JOIN `202_clicks_advance` AS 2ca USING (`click_id`)
LEFT JOIN `202_google` AS 2g USING (`click_id`)
LEFT JOIN `202_clicks` AS 2c USING (`click_id`)
LEFT JOIN `202_tracking_c1` AS 2c1 USING (`c1_id`)
LEFT JOIN `202_tracking_c2` AS 2c2 USING (`c2_id`)
LEFT JOIN `202_tracking_c3` AS 2c3 USING (`c3_id`)
LEFT JOIN `202_tracking_c4` AS 2c4 USING (`c4_id`)
LEFT JOIN `202_utm_source` AS 2us USING (`utm_source_id`)
LEFT JOIN `202_utm_medium` AS 2um USING (`utm_medium_id`)
LEFT JOIN `202_utm_campaign` AS 2uca USING (`utm_campaign_id`)
LEFT JOIN `202_utm_term` AS 2ut USING (`utm_term_id`)
LEFT JOIN `202_utm_content` AS 2uco USING (`utm_content_id`)
LEFT JOIN `202_keywords` AS 2kw ON (2ca.`keyword_id` = 2kw.`keyword_id`)
LEFT JOIN `202_cpa_trackers` AS 2cpa USING (`click_id`)
LEFT JOIN `202_trackers` AS 2trc ON (2cpa.`tracker_id_public` = 2trc.`tracker_id_public`)".$site_urls."
WHERE 2c.`click_id` = {$mysql['click_id']}
LIMIT 1";


$cvar_sql_result = $db->query($cvar_sql);
$cvar_sql_row = $cvar_sql_result->fetch_assoc();
$mysql['t202kw'] = $db->real_escape_string($cvar_sql_row['keyword']);
$mysql['c1'] = $db->real_escape_string($cvar_sql_row['c1']);
$mysql['c2'] = $db->real_escape_string($cvar_sql_row['c2']);
$mysql['c3'] = $db->real_escape_string($cvar_sql_row['c3']);
$mysql['c4'] = $db->real_escape_string($cvar_sql_row['c4']);
$mysql['gclid'] = $db->real_escape_string($cvar_sql_row['gclid']);
$mysql['utm_source'] = $db->real_escape_string($cvar_sql_row['utm_source']);
$mysql['utm_medium'] = $db->real_escape_string($cvar_sql_row['utm_medium']);
$mysql['utm_campaign'] = $db->real_escape_string($cvar_sql_row['utm_campaign']);
$mysql['utm_term'] = $db->real_escape_string($cvar_sql_row['utm_term']);
$mysql['utm_content'] = $db->real_escape_string($cvar_sql_row['utm_content']);
$mysql['click_user_id'] = $db->real_escape_string($cvar_sql_row['user_id']);
$mysql['campaign_id'] = $db->real_escape_string($cvar_sql_row['aff_campaign_id']);
$mysql['payout'] = $db->real_escape_string($cvar_sql_row['click_payout']);
$mysql['cpc'] = $db->real_escape_string($cvar_sql_row['click_cpc']);
$mysql['click_cpa'] = $db->real_escape_string($cvar_sql_row['click_cpa']);
$mysql['click_lead'] = $db->real_escape_string($cvar_sql_row['click_lead']);
$mysql['click_time'] = $db->real_escape_string($cvar_sql_row['click_time']);
$mysql['referer'] = urlencode((string) $db->real_escape_string($cvar_sql_row['site_url_address']));

if ($_GET['amount'] && is_numeric($_GET['amount'])) {
	$mysql['use_pixel_payout'] = 1;
	$mysql['payout'] = $db->real_escape_string((string)$_GET['amount']);
	$mysql['click_payout'] = $db->real_escape_string((string)$_GET['amount']);
}

$tokens = [
    "subid" => $mysql['click_id'],
    "t202kw" => $mysql['t202kw'],
	"c1" => $mysql['c1'],
	"c2" => $mysql['c2'],
	"c3" => $mysql['c3'],
	"c4" => $mysql['c4'],
    "gclid" => $mysql['gclid'],
    "utm_source" => $mysql['utm_source'],
    "utm_medium" => $mysql['utm_medium'],
    "utm_campaign" => $mysql['utm_campaign'],
    "utm_term" => $mysql['utm_term'],
    "utm_content" => $mysql['utm_content'],
    "cpc" => round($mysql['cpc'], 2),
	"cpc2" => $mysql['cpc'],
    "timestamp" => time(),
	"payout" => $mysql['payout'],
	"random" => mt_rand(1000000, 9999999),
    "referer" => $mysql['referer']
];

$account_id_sql="SELECT 202_clicks.ppc_account_id
				 FROM 202_clicks 
				 WHERE click_id={$mysql['click_id']}";

$account_id_result = $db->query($account_id_sql);
$account_id_row = $account_id_result->fetch_assoc();
$mysql['ppc_account_id'] = $db->real_escape_string($account_id_row['ppc_account_id']);

if($mysql['ppc_account_id']){
	$pixel_sql='SELECT 202_ppc_account_pixels.pixel_code,202_ppc_account_pixels.pixel_type_id FROM 202_ppc_account_pixels WHERE 202_ppc_account_pixels.ppc_account_id='.$mysql['ppc_account_id'];
	
	$pixel_result = $db->query($pixel_sql);

	$pixel_result_row = $pixel_result->fetch_assoc();
	$mysql['pixel_type_id'] = $db->real_escape_string($pixel_result_row['pixel_type_id']);
	if ($mysql['pixel_type_id'] == 5) {
		$mysql['pixel_code'] = stripslashes((string) $pixel_result_row['pixel_code']);
	}else{
		$mysql['pixel_code'] = $db->real_escape_string($pixel_result_row['pixel_code']);
	}

	//get the list of pixel urls
    if($mysql['pixel_type_id'] != 5) $pixel_urls = explode(' ',(string) $mysql['pixel_code']);
   
	switch ($mysql['pixel_type_id']) {
		case 1:
			header('HTTP/1.1 202 Accepted', true, 202);
			foreach($pixel_urls as $pixel_url){
			  if(isset($pixel_url))
			    $pixel_url=replaceTokens($pixel_url,$tokens);
			    echo "<img src='{$pixel_url}' height='0' width='0' style='display:none' />\n";  
			}

			break;
		case 2:
			header('HTTP/1.1 202 Accepted', true, 202);
	        foreach($pixel_urls as $pixel_url){
			  if(isset($pixel_url))
			    $pixel_url=replaceTokens($pixel_url,$tokens);
			 
			    echo "<iframe src='{$pixel_url}' height='0' width='0'></iframe>\n";  
			}

			break;
		case 3:
			header('HTTP/1.1 202 Accepted', true, 202);
	        foreach($pixel_urls as $pixel_url){
			  if(isset($pixel_url))
			   $pixel_url=replaceTokens($pixel_url,$tokens);
			  
			   echo "<script async src='{$pixel_url}'></script>\n";
			}

			break;
		case 4:
			$snoopy = new Snoopy;
			$snoopy->agent="Mozilla/5.0 Postback202-Bot v1.8";

        	foreach($pixel_urls as $pixel_url){
			  if(isset($pixel_url))
			    $pixel_url=replaceTokens($pixel_url,$tokens);
			    $snoopy->fetchtext($pixel_url);
			    header('HTTP/1.1 202 Accepted', true, 202);
			    header('Content-Type: application/json');
			    $response = ['error' => false, 'code' => 202, 'msg' => 'Postback successful'];
			    print_r(json_encode($response));

			}
			break;

		case 5:
			echo $mysql['pixel_code'];

			break;

	}
}

if (is_numeric($mysql['click_id'])) {

	$conv_time = time();
	$click_time_to_date = new DateTime(date('Y-m-d h:i:s', $mysql['click_time']));
	$conv_time_to_date = new DateTime(date('Y-m-d h:i:s', $conv_time));
	$diff = $click_time_to_date->diff($conv_time_to_date);
	$mysql['time_difference'] =  $db->real_escape_string($diff->d.' days, '.$diff->h.' hours, '.$diff->i.' min and '.$diff->s.' sec');
	$mysql['conv_time'] = $db->real_escape_string($conv_time);
	$mysql['ip'] = $db->real_escape_string($_SERVER['HTTP_X_FORWARDED_FOR']);
	$mysql['user_agent'] = $db->real_escape_string($_SERVER['HTTP_USER_AGENT']);

	if ($mysql['click_cpa']) {
		$sql_set = "click_cpc='".$mysql['click_cpa']."', click_lead='1', click_filtered='0'";
	} else {
		$sql_set = "click_lead='1', click_filtered='0'";
	}

	if ($_GET['amount'] && is_numeric($_GET['amount'])) {
		$mysql['use_pixel_payout'] = 1;
		$mysql['click_payout'] = $db->real_escape_string((string)$_GET['amount']);
	}

	$click_sql = "
		UPDATE
			202_clicks 
		SET
			".$sql_set."
	";
	if ($mysql['use_pixel_payout']==1) {
		$click_sql .= "
			, click_payout='".$mysql['click_payout']."'
		";
	}
	$click_sql .= "
		WHERE
			click_id='".$mysql['click_id']."'
	";
	$db->query($click_sql);

	$click_sql = "
		UPDATE
			202_clicks_spy 
		SET
			".$sql_set."
	";
	if ($mysql['use_pixel_payout']==1) {
		$click_sql .= "
			, click_payout='".$mysql['click_payout']."'
		";
	}
	$click_sql .= "
		WHERE
			click_id='".$mysql['click_id']."'
	";
	//delay_sql($db, $click_sql);
	$db->query($click_sql);
	
	$log_sql = "INSERT INTO 202_conversion_logs
				SET conv_id = DEFAULT,
					click_id = '".$mysql['click_id']."',
					campaign_id = '".$mysql['campaign_id']."',
					user_id = '".$mysql['click_user_id']."',
					click_time = '".$mysql['click_time']."',
					conv_time = '".$mysql['conv_time']."',
					time_difference = '".$mysql['time_difference']."',
					ip = '".$mysql['ip']."',
					pixel_type = '2',
					user_agent = '".$mysql['user_agent']."'";
        $db->query($log_sql);
        $conversionId = (int) $db->insert_id;

        if ($conversionId > 0) {
                try {
                        $journeyRepository = new ConversionJourneyRepository($db);
                        $journeyRepository->persistJourney(
                                conversionId: $conversionId,
                                userId: (int) $mysql['click_user_id'],
                                campaignId: (int) $mysql['campaign_id'],
                                conversionTime: (int) $mysql['conv_time'],
                                primaryClickId: (int) $mysql['click_id'],
                                primaryClickTime: (int) $mysql['click_time']
                        );
                } catch (Throwable $journeyError) {
                        error_log('Failed to persist conversion journey for conv_id ' . $conversionId . ': ' . $journeyError->getMessage());
                }
        }
					
	//set dirty hour
	$de = new DataEngine();
	$data=($de->setDirtyHour($mysql['click_id']));
}

