<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();


//show real or filtered clicks
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT user_pref_breakdown, user_pref_show, user_cpc_or_cpv FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
	$user_result = _mysqli_query($user_sql, $dbGlobalLink); //($user_sql);
	$user_row = $user_result->fetch_assoc();
	$breakdown = $user_row['user_pref_breakdown'];

	if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
	if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
	if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
	if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; }

	if ($user_row['user_cpc_or_cpv'] == 'cpv')  $cpv = true;
	else 										$cpv = false;

	//lets build the new keyword list
			$db_table = '2c';
			$query = query('
						SELECT 
							2ca.browser_id AS browser_id,
							2b.browser_name AS browser_name, 
							COUNT(*) AS clicks, 
							SUM(2cr.click_out) AS click_out, 
							SUM(2c.click_lead) AS leads, 
							2ac.aff_campaign_payout AS payout, 
							SUM(2c.click_payout*2c.click_lead) AS income, 
							SUM(2c.click_cpc) AS cost 
						FROM 202_clicks AS 2c 
						LEFT OUTER JOIN 202_clicks_record AS 2cr ON (2c.click_id = 2cr.click_id) 
						LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id) 
						LEFT OUTER JOIN 202_clicks_advance AS 2ca ON (2c.click_id = 2ca.click_id) 
						LEFT OUTER JOIN 202_browsers AS 2b ON (2ca.browser_id = 2b.browser_id)
			', $db_table, true, true, false, " $click_filtered GROUP BY browser_id ORDER BY clicks DESC", $_POST['offset'], true, true);
			$info_sql = $query['click_sql'];
			$info_sql = $query['click_sql'];
			$info_result = $db->query($info_sql) or record_mysql_error($info_sql); 
			$total_rows = $info_result->num_rows;


header("Content-type: application/octet-stream");

# replace excelfile.xls with whatever you want the filename to default to
header("Content-Disposition: attachment; filename=T202_browsers_".time().".xls");
header("Pragma: no-cache");
header("Expires: -1");
	
	
echo "Browser" . "\t" . "Clicks" . "\t" . "Click Throughs" . "\t" . "LP CTR" . "\t" . "Leads" . "\t" . "S/U"  . "\t" . "Payout"  . "\t" . "EPC"  . "\t" . "Avg CPC"  . "\t" . "Income"  . "\t" . "Cost"  . "\t" . "Net" . "\t" . "ROI"  . "\n";

if ($total_rows > 0) {

				while ($click_row = $info_result->fetch_assoc()) {

					$html['browser_name'] = htmlentities($click_row['browser_name'], ENT_QUOTES, 'UTF-8');
							

					//grab the variables
						$clicks = 0;
						$clicks = $click_row['clicks'];

						$click_throughs = 0;
						$click_throughs = $click_row['click_out'];

					//ctr rate
						$ctr_ratio = 0;
						$ctr_ratio = @round($click_throughs/$clicks*100,2);

					//cost
						$cost = 0;
						$cost = $click_row['cost'];

					//avg cpc and cost
						$avg_cpc = 0;
						$avg_cpc = $click_row['avg_cpc'];

					//leads
						$leads = 0;
						$leads = $click_row['leads'];

					//signup ratio
						$su_ratio - 0;
						$su_ratio = @round($leads/$clicks*100,2);

					//current payout
						$payout = 0;
						$payout = $click_row['payout'];

					//income
						$income = 0;
						$income = $click_row['income'];

					//grab the EPC
						$epc = 0;
						$epc = @round($income/$clicks,2);

					//net income
						$net = 0;
						$net = $income - $cost;

					//roi
						$roi = 0;
						$roi = @round($net/$cost*100);


						echo 
						$html['browser_name'] . "\t" .
						$clicks . "\t" .
						$click_throughs . "\t" .
						$ctr_ratio . "\t" .
						$leads . "\t" .
						$su_ratio.'%' . "\t" .
						dollar_format($payout) . "\t" .
						dollar_format($epc) . "\t" .
						dollar_format($avg_cpc, $cpv) . "\t" .
						dollar_format($income) . "\t" .
						dollar_format($cost, $cpv) . "\t" .
						dollar_format($net, $cpv) . "\t" .
						$roi.'%' . "\n";

				}
			} 
		
?>
