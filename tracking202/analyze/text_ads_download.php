<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

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
		$mysql['order'] = ' ORDER BY sort_text_ad_clicks DESC';   
	}
	$db_table = '202_sort_text_ads';
							 
	$query = query('SELECT * FROM 202_sort_text_ads LEFT JOIN 202_text_ads USING (text_ad_id)', $db_table, false, false, false,  $mysql['order'],false, false, true);
	$keyword_sql = $query['click_sql'];
	$keyword_result = mysql_query($keyword_sql) or record_mysql_error($keyword_sql);  
	

header("Content-type: application/octet-stream");

# replace excelfile.xls with whatever you want the filename to default to
header("Content-Disposition: attachment; filename=T202_textads_".time().".xls");
header("Pragma: no-cache");
header("Expires: 0");
	
	
echo "Text Ad" . "\t" . "Clicks" . "\t" . "Leads" . "\t" . "S/U"  . "\t" . "Payout"  . "\t" . "EPC"  . "\t" . "Avg CPC"  . "\t" . "Income"  . "\t" . "Cost"  . "\t" . "Net" . "\t" . "ROI"  . "\n";

 
while ($keyword_row = mysql_fetch_array($keyword_result, MYSQL_ASSOC)) { 
	
	if (!$keyword_row['text_ad_name']) { 
		$keyword_row['text_ad_name'] = '[no text ad recorded]';    
	} 

	echo 
	$keyword_row['text_ad_name'] . "\t" . 
	$keyword_row['sort_text_ad_clicks'] . "\t" .
	$keyword_row['sort_text_ad_leads'] . "\t" .
	$keyword_row['sort_text_ad_su_ratio'].'%' . "\t" .
	dollar_format($keyword_row['sort_text_ad_payout']) . "\t" .
	dollar_format($keyword_row['sort_text_ad_epc']) . "\t" .
	dollar_format($keyword_row['sort_text_ad_avg_cpc'], $cpv) . "\t" .
	dollar_format($keyword_row['sort_text_ad_income']) . "\t" .
	dollar_format($keyword_row['sort_text_ad_cost'], $cpv) . "\t" .
	dollar_format($keyword_row['sort_text_ad_net'], $cpv) . "\t" .
	$keyword_row['sort_text_ad_roi'].'%' . "\n";
	
}
