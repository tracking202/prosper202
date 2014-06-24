<?
header('P3P: CP="Prosper202 does not have a P3P policy"');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect2.php');
include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/class-snoopy.php');

//get the aff_camapaign_id
$mysql['user_id'] = 1;
$mysql['use_pixel_payout'] = 0;
$mysql['cid'] = 0;
$mysql['click_id'] = 0;

//first grab the cid
if(array_key_exists('subid',$_GET) && is_numeric($_GET['subid'])) {
	$mysql['subid']= $db->real_escape_string($_GET['subid']);
} elseif(array_key_exists('sid',$_GET) && is_numeric($_GET['sid'])) {
	$mysql['subid']= $db->real_escape_string($_GET['sid']);
} else {
	header('HTTP/1.1 404 Not Found', true, 404);
	header('Content-Type: application/json');
	$response = array('error' => true, 'code' => 404, 'msg' => 'SubID not found');
	print_r(json_encode($response));
	die();
}

$mysql['click_id'] = $mysql['subid'];


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
	$response = array('error' => true, 'code' => 404, 'msg' => 'SubID not found');
	print_r(json_encode($response));
	die();
}

//get c1-c4 values etc
$cvar_sql ="
SELECT 
	2cid.click_id,
	2c.click_payout,
	2c.click_cpc,
	2c1.c1,
	2c2.c2,
	2c3.c3,
	2c4.c4,
	2kw.keyword
FROM `202_clicks_tracking` AS 2cid
LEFT JOIN `202_clicks_advance` AS 2ca USING (`click_id`)
LEFT JOIN `202_clicks` AS 2c USING (`click_id`)
LEFT JOIN `202_tracking_c1` AS 2c1 USING (`c1_id`)
LEFT JOIN `202_tracking_c2` AS 2c2 USING (`c2_id`)
LEFT JOIN `202_tracking_c3` AS 2c3 USING (`c3_id`)
LEFT JOIN `202_tracking_c4` AS 2c4 USING (`c4_id`)
LEFT JOIN `202_keywords` AS 2kw ON (2ca.`keyword_id` = 2kw.`keyword_id`)
WHERE `click_id` = {$mysql['click_id']}
LIMIT 1";


$cvar_sql_result = $db->query($cvar_sql);
$cvar_sql_row = $cvar_sql_result->fetch_assoc();
$mysql['t202kw'] = $db->real_escape_string($cvar_sql_row['keyword']);
$mysql['c1'] = $db->real_escape_string($cvar_sql_row['c1']);
$mysql['c2'] = $db->real_escape_string($cvar_sql_row['c2']);
$mysql['c3'] = $db->real_escape_string($cvar_sql_row['c3']);
$mysql['c4'] = $db->real_escape_string($cvar_sql_row['c4']);
$mysql['payout'] = $db->real_escape_string($cvar_sql_row['click_payout']);
$mysql['cpc'] = $db->real_escape_string($cvar_sql_row['click_cpc']);

if ($_GET['amount'] && is_numeric($_GET['amount'])) {
	$mysql['use_pixel_payout'] = 1;
	$mysql['payout'] = $db->real_escape_string($_GET['amount']);
	$mysql['click_payout'] = $db->real_escape_string($_GET['amount']);
}

$tokens = array(
    "subid" => $mysql['click_id'],
    "t202kw" => $mysql['t202kw'],
	"c1" => $mysql['c1'],
	"c2" => $mysql['c2'],
	"c3" => $mysql['c3'],
	"c4" => $mysql['c4'],
	"cpc" => round($mysql['cpc'], 2),
	"cpc2" => $mysql['cpc'],
	"payout" => $mysql['payout'],
	"random" => mt_rand(1000000, 9999999)
);

$account_id_sql="SELECT 202_clicks.ppc_account_id
				 FROM 202_clicks 
				 WHERE click_id={$mysql['click_id']}";

$account_id_result = $db->query($account_id_sql);
$account_id_row = $account_id_result->fetch_assoc();
$mysql['ppc_account_id'] = $db->real_escape_string($account_id_row['ppc_account_id']);
//$mysql['ppc_account_id']=1; //commenut out in live
if($mysql['ppc_account_id']){
	$pixel_sql='SELECT 202_ppc_account_pixels.pixel_code,202_ppc_account_pixels.pixel_type_id FROM 202_ppc_account_pixels WHERE 202_ppc_account_pixels.ppc_account_id='.$mysql['ppc_account_id'];
	//"SELECT 202_ppc_account_pixels.pixel_code,202_ppc_account_pixels.pixel_type_id FROM 202_ppc_account_pixels LEFT JOIN 202_clicks ON 202_clicks.ppc_account_id=202_ppc_account_pixels.ppc_account_id WHERE 202_ppc_account_pixels.ppc_account_id=".$mysql['ppc_account_id'];
    
	$pixel_result = $db->query($pixel_sql);

	$pixel_result_row = $pixel_result->fetch_assoc();
	//$pixel_result_row = memcache_mysql_fetch_assoc($pixel_sql);
	$mysql['pixel_type_id'] = $db->real_escape_string($pixel_result_row['pixel_type_id']);
	if ($mysql['pixel_type_id'] == 5) {
		$mysql['pixel_code'] = stripslashes($pixel_result_row['pixel_code']);
	}else{
		$mysql['pixel_code'] = $db->real_escape_string($pixel_result_row['pixel_code']);
	}

	//get the list of pixel urls
    if($mysql['pixel_type_id'] != 5) $pixel_urls = explode(' ',$mysql['pixel_code']);
   
	switch ($mysql['pixel_type_id']) {
		case 1:

			foreach($pixel_urls as $pixel_url){
			  if(isset($pixel_url))
			    $pixel_url=replaceTokens($pixel_url,$tokens);
			    echo "<img src='{$pixel_url}' height='0' width='0' style='display:none' />\n";  
			}

			break;
		case 2:

	        foreach($pixel_urls as $pixel_url){
			  if(isset($pixel_url))
			    $pixel_url=replaceTokens($pixel_url,$tokens);
			    echo "<iframe src='{$pixel_url}' height='0' width='0'></iframe>\n";  
			}

			break;
		case 3:

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
			    $response = array('error' => false, 'code' => 202, 'msg' => 'Postback succesfull');
			    print_r(json_encode($response));
			    print_r(json_encode($tokens));

			    echo $pixel_url;
			}
			break;

		case 5:
			echo $mysql['pixel_code'];

			break;

	}
}

if (is_numeric($mysql['click_id'])) {

	if ($_GET['amount'] && is_numeric($_GET['amount'])) {
		$mysql['use_pixel_payout'] = 1;
		$mysql['click_payout'] = $db->real_escape_string($_GET['amount']);
	}

	$click_sql = "
		UPDATE
			202_clicks 
		SET
			click_lead='1', 
			click_filtered='0'
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
	delay_sql($db, $click_sql);

	$click_sql = "
		UPDATE
			202_clicks_spy 
		SET
			click_lead='1',
			click_filtered='0'
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
	delay_sql($db, $click_sql);
}

	header('HTTP/1.1 202 Accepted', true, 202);
	header('Content-Type: application/json');
	$response = array('error' => false, 'code' => 202, 'msg' => 'Postback succesfull');
	print_r(json_encode($response));