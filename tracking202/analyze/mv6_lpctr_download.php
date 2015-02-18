<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


//show real or filtered clicks
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT user_pref_breakdown, user_pref_show, user_cpc_or_cpv FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
	$user_result = _mysql_query($user_sql, $dbGlobalLink); //($user_sql);
	$user_row = mysql_fetch_assoc($user_result);
	$breakdown = $user_row['user_pref_breakdown'];

	if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
	if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
	if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
	if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; }

	if ($user_row['user_cpc_or_cpv'] == 'cpv')  $cpv = true;
	else 										$cpv = false;



//keywords already set in the table, just just download them
$mysql['order'] = ' ORDER BY 202_sort_mv6_lpctr.sort_mv6_clicks DESC';

$db_table = '202_sort_mv6_lpctr';

$query = query('SELECT * FROM 202_sort_mv6_lpctr LEFT JOIN 202_tracking_mv6 ON (202_sort_mv6_lpctr.mv6_id = 202_tracking_mv6.mv6_id)', $db_table, false, false, false,  $mysql['order'], $_POST['offset'], true, true);
$keyword_sql = $query['click_sql'];
$keyword_result = mysql_query($keyword_sql) or record_mysql_error($keyword_sql);
	 
	

header("Content-type: application/octet-stream");

# replace excelfile.xls with whatever you want the filename to default to
header("Content-Disposition: attachment; filename=T202_tracking_mv6_".time().".xls");
header("Pragma: no-cache");
header("Expires: -1");
	
	
echo "Snippet F" . "\t" . "Clicks" . "\t" . "Click Throughs" . "\t" . "LP CTR" . "\t" . "Leads" . "\t" . "S/U"  . "\t" . "Payout"  . "\t" . "EPC"  . "\t" . "Avg CPC"  . "\t" . "Income"  . "\t" . "Cost"  . "\t" . "Net" . "\t" . "ROI"  . "\n";


while ($keyword_row = mysql_fetch_array($keyword_result, MYSQL_ASSOC)) { 
	
	if (!$keyword_row['mv6']) { 
		$keyword_row['mv6'] = '[Snippet F unused]';    
	} 

	echo 
	$keyword_row['mv6'] . "\t" . 
	$keyword_row['sort_mv6_clicks'] . "\t" .
	$keyword_row['sort_mv6_click_throughs'] . "\t" .
	$keyword_row['sort_mv6_ctr'] . "\t" .
	$keyword_row['sort_mv6_leads'] . "\t" .
	$keyword_row['sort_mv6_su_ratio'].'%' . "\t" .
	dollar_format($keyword_row['sort_mv6_payout']) . "\t" .
	dollar_format($keyword_row['sort_mv6_epc']) . "\t" .
	dollar_format($keyword_row['sort_mv6_cpc'], $cpv) . "\t" .
	dollar_format($keyword_row['sort_mv6_income']) . "\t" .
	dollar_format($keyword_row['sort_mv6_cost'], $cpv) . "\t" .
	dollar_format($keyword_row['sort_mv6_net'], $cpv) . "\t" .
	$keyword_row['sort_mv6_roi'].'%' . "\n";
	
}
