<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

//make sure user is logged in
	AUTH::require_user();

	
	//show real or filtered clicks
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT user_pref_breakdown, user_pref_show, user_cpc_or_cpv FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
	$user_result = _mysql_query($user_sql, $dbGlobalLink); //($user_sql);
	$user_row = mysql_fetch_assoc($user_result);	
	$breakdown = $user_row['user_pref_breakdown'];

	if ($user_row['user_cpc_or_cpv'] == 'cpv')  $cpv = true;
	else 										$cpv = false;
	
	
	
//keywords already set in the table, just just download them
	if (empty($mysql['order'])) { 
		$mysql['order'] = ' ORDER BY sort_landing_page_clicks DESC';   
	}
	
	$db_table = '202_sort_landing_pages';	 
	$query = query('SELECT * FROM 202_sort_landing_pages LEFT JOIN 202_landing_pages USING (landing_page_id)', $db_table, false, false, false,  $mysql['order'],false, false, true);
	$keyword_sql = $query['click_sql'];
	$keyword_result = mysql_query($keyword_sql) or record_mysql_error($keyword_sql);  
	

header("Content-type: application/octet-stream");

# replace excelfile.xls with whatever you want the filename to default to
header("Content-Disposition: attachment; filename=T202_landing_pages_".time().".xls");
header("Pragma: no-cache");
header("Expires: 0");  
	
	 
echo "Landing Page" . "\t" . "Clicks" . "\t" . "Click Throughs" . "\t" . "CTR" . "\t" . "Leads" . "\t" . "S/U"  . "\t" . "Payout"  . "\t" . "EPC"  . "\t" . "Avg CPC"  . "\t" . "Income"  . "\t" . "Cost"  . "\t" . "Net" . "\t" . "ROI"  . "\n";

 
while ($keyword_row = mysql_fetch_array($keyword_result, MYSQL_ASSOC)) { 
	
	
	if (!$keyword_row['landing_page_nickname']) { 
		$keyword_row['landing_page_nickname'] =  '[direct link]';    
	} 

	echo 
	$keyword_row['landing_page_nickname'] . "\t" . 
	$keyword_row['sort_landing_page_clicks'] . "\t" .
	$keyword_row['sort_landing_page_click_throughs'] .'%' . "\t" .
	$keyword_row['sort_landing_page_ctr'] . "\t" .
	$keyword_row['sort_landing_page_leads'] . "\t" .
	$keyword_row['sort_landing_page_su_ratio'].'%' . "\t" .
	dollar_format($keyword_row['sort_landing_page_payout']) . "\t" .
	dollar_format($keyword_row['sort_landing_page_epc']) . "\t" .
	dollar_format($keyword_row['sort_landing_page_avg_cpc'], $cpv) . "\t" .
	dollar_format($keyword_row['sort_landing_page_income']) . "\t" .
	dollar_format($keyword_row['sort_landing_page_cost'], $cpv) . "\t" .
	dollar_format($keyword_row['sort_landing_page_net'], $cpv) . "\t" .  
	$keyword_row['sort_landing_page_roi'].'%' . "\n"; 
	
}
