<? //write out a transparent 1x1 gif
header("content-type: image/gif"); 
header('Content-Length: 43');
header('Cache-Control: no-cache, no-store, max-age=0, must-revalidate');
header('Expires: Sun, 03 Feb 2008 05:00:00 GMT'); // Date in the past
header("Pragma: no-cache");
header('P3P: CP="IDC DSP COR CURa ADMa OUR IND PHY ONL COM STA"');
echo base64_decode("R0lGODlhAQABAIAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==");

include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect2.php'); 

//get the aff_camapaign_id
$mysql['user_id'] = 1;
$mysql['click_id'] = 0;
$mysql['cid'] = 0;
$mysql['use_pixel_payout'] = 0;

//first grab the cid
if(array_key_exists('cid',$_GET) && is_numeric($_GET['cid'])) {
	$mysql['cid']= mysql_real_escape_string($_GET['cid']);
}

//see if it has the cookie in the campaign id, then the general match, then do whatever we can to grab SOMETHING to tie this lead to
if ($_COOKIE['tracking202subid_a_'.$mysql['cid']]) {
	$mysql['click_id'] = mysql_real_escape_string($_COOKIE['tracking202subid_a_' . $mysql['cid']]);
} else if ($_COOKIE['tracking202subid']) {
	$mysql['click_id'] = mysql_real_escape_string($_COOKIE['tracking202subid']);
} else  {
	//ok grab the last click from this ip_id
	$mysql['ip_address'] = mysql_real_escape_string($_SERVER['REMOTE_ADDR']);
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
	$click_result1 = mysql_query($click_sql1) or record_mysql_error($click_sql1);
	$click_row1 = mysql_fetch_assoc($click_result1);
	$mysql['click_id'] = mysql_real_escape_string($click_row1['click_id']);
}

if (is_numeric($mysql['click_id'])) {

	if ($_GET['amount'] && is_numeric($_GET['amount'])) {
		$mysql['use_pixel_payout'] = 1;
		$mysql['click_payout'] = mysql_real_escape_string($_GET['amount']);
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
	delay_sql($click_sql);

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
	delay_sql($click_sql);
}
