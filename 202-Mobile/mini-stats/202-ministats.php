<?php

//grab the users date range preferences
$time = grab_timeframe();
$mysql['to'] = $db->real_escape_string($time['to']);
$mysql['from'] = $db->real_escape_string($time['from']);

//show real or filtered clicks
$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
$user_sql = "SELECT user_pref_show, user_cpc_or_cpv FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
$user_result = _mysqli_query($user_sql); //($user_sql);
$user_row = $user_result->fetch_assoc();

if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; }

if ($user_row['user_cpc_or_cpv'] == 'cpv')  $cpv = true;
else 										$cpv = false;

$info_sql = "
				SELECT     	202_aff_campaigns.aff_campaign_id,
							aff_campaign_name,
							aff_campaign_payout,
							aff_network_name
				 FROM      	202_summary_overview 
				 LEFT JOIN 202_aff_campaigns USING (aff_campaign_id) 
				 LEFT JOIN 202_aff_networks USING(aff_network_id) 
				 WHERE   	202_aff_networks.user_id='".$mysql['user_id']."'
				 AND        	202_aff_networks.aff_network_deleted = 0
				 AND        	202_aff_campaigns.aff_campaign_deleted = 0 
				 AND        	202_summary_overview.click_time >= ".$mysql['from']."
				 AND        	202_summary_overview.click_time < ".$mysql['to']."
				 AND 		landing_page_id=0 
				 GROUP BY  aff_campaign_id 
				 ORDER BY  202_aff_networks.aff_network_name ASC,
							 202_aff_campaigns.aff_campaign_name ASC"; 
$info_result = _mysqli_query($info_sql) ; //($info_sql);

while ($info_row = $info_result->fetch_array(MYSQL_ASSOC)) {
	//mysql escape the vars
	$mysql['aff_campaign_id'] = $db->real_escape_string($info_row['aff_campaign_id']);
	$mysql['landing_page_id'] = $db->real_escape_string($info_row['landing_page_id']);

	$click_sql = "
				SELECT
					COUNT(*) AS clicks,
					AVG(2c.click_cpc) AS avg_cpc,
					SUM(2c.click_lead) AS leads,
					SUM(2c.click_payout*2c.click_lead) AS income
				FROM
					202_clicks AS 2c
				WHERE
					2c.user_id='".$mysql['user_id']."'
					$click_filtered
					AND 2c.aff_campaign_id='".$mysql['aff_campaign_id']."'
					AND 2c.click_time > ".$mysql['from'] ."
					AND 2c.click_time <= ".$mysql['to']."
					AND 2c.click_alp=0
			";
					$click_result = _mysqli_query($click_sql) ; //($click_sql);
					$click_row = $click_result->fetch_assoc();

					//get the stats
					$clicks = 0;
					$clicks = $click_row['clicks'];

					$total_clicks = $total_clicks + $clicks;

					//avg cpc and cost
					$avg_cpc = 0;
					$avg_cpc = $click_row['avg_cpc'];

					$cost = 0;
					$cost = $clicks * $avg_cpc;

					$total_cost = $total_cost + $cost;
					$total_avg_cpc = @round($total_cost/$total_clicks, 5);

					//leads
					$leads = 0;
					$leads = $click_row['leads'];

					$total_leads = $total_leads + $leads;

					//signup ratio
					$su_ratio - 0;
					$su_ratio = @round($leads/$clicks*100,2);

					$total_su_ratio = @round($total_leads/$total_clicks*100,2);

					//current payout
					$payout = 0;
					$payout = $info_row['aff_campaign_payout'];

					//income
					$income = 0;
					$income = $click_row['income'];

					$total_income = $total_income + $income;

					//grab the EPC
					$epc = 0;
					$epc = @round($income/$clicks,2);

					$total_epc = @round($total_income/$total_clicks,2);

					//net income
					$net = 0;
					$net = $income - $cost;

					$total_net = $total_income - $total_cost;

					//roi
					$roi = 0;
					$roi = @@round($net/$cost*100);

					$total_roi = @round($total_net/$total_cost*100); }

					$html['total_clicks'] = htmlentities($total_clicks, ENT_QUOTES, 'UTF-8');
					$html['total_leads'] = htmlentities($total_leads, ENT_QUOTES, 'UTF-8');
					$html['total_su_ratio'] = htmlentities($total_su_ratio.'%', ENT_QUOTES, 'UTF-8');
					$html['total_epc'] = htmlentities(dollar_format($total_epc), ENT_QUOTES, 'UTF-8');
					$html['total_avg_cpc'] = htmlentities(dollar_format($total_avg_cpc, $cpv), ENT_QUOTES, 'UTF-8');
					$html['total_income'] = htmlentities(dollar_format($total_income), ENT_QUOTES, 'UTF-8');
					$html['total_cost'] = htmlentities(dollar_format($total_cost, $cpv), ENT_QUOTES, 'UTF-8');
					$html['total_net'] = htmlentities(dollar_format($total_net, $cpv), ENT_QUOTES, 'UTF-8');
					$html['total_roi'] = htmlentities($total_roi.'%', ENT_QUOTES, 'UTF-8');
					?>