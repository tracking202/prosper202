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


//ips already set in the table, just just download them
	if (empty($mysql['order'])) { 
		$mysql['order'] = ' ORDER BY sort_ip_clicks DESC';   
	}
	$db_table = '202_sort_ips';
							 
	$query = query('SELECT * FROM 202_sort_ips LEFT JOIN 202_ips USING (ip_id)', $db_table, false, false, false,  $mysql['order'],false, false, true);
	$ip_sql = $query['click_sql'];
	$ip_result = mysql_query($ip_sql) or record_mysql_error($ip_sql);  
	

header("Content-type: application/octet-stream");

# replace excelfile.xls with whatever you want the filename to default to
header("Content-Disposition: attachment; filename=T202_ips_".time().".xls");
header("Pragma: no-cache");
header("Expires: 0");
	
	
echo "ip" . "\t" . "Clicks" . "\t" . "Leads" . "\t" . "S/U"  . "\t" . "Payout"  . "\t" . "EPC"  . "\t" . "Avg CPC"  . "\t" . "Income"  . "\t" . "Cost"  . "\t" . "Net" . "\t" . "ROI"  . "\n";


while ($ip_row = mysql_fetch_array($ip_result, MYSQL_ASSOC)) { 
	
	if (!$ip_row['ip_address']) { 
		$ip_row['ip_address'] = '[no ip]';    
	} 

	echo 
	$ip_row['ip_address'] . "\t" . 
	$ip_row['sort_ip_clicks'] . "\t" .
	$ip_row['sort_ip_leads'] . "\t" .
	$ip_row['sort_ip_su_ratio'].'%' . "\t" .
	dollar_format($ip_row['sort_ip_payout']) . "\t" .
	dollar_format($ip_row['sort_ip_epc']) . "\t" .
	dollar_format($ip_row['sort_ip_avg_cpc'], $cpv) . "\t" .
	dollar_format($ip_row['sort_ip_income']) . "\t" .
	dollar_format($ip_row['sort_ip_cost'], $cpv) . "\t" .
	dollar_format($ip_row['sort_ip_net'], $cpv) . "\t" .
	$ip_row['sort_ip_roi'].'%' . "\n";
	
}
