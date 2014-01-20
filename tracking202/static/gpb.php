<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 


if (!$_GET['subid'] and !$_GET['sid']) die();

$click_id = $_GET['subid'];
if ($_GET['sid']) 
	$click_id = $_GET['sid'];

$mysql['user_id'] = 1;
$mysql['click_id'] = mysql_real_escape_string($click_id);
$mysql['pixel_id'] = 0;
$mysql['use_pixel_payout'] = 0;

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