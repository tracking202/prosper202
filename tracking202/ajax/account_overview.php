<? include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php'); 

AUTH::require_user();
	
//set the timezone for this user.
	AUTH::set_timezone($_SESSION['user_timezone']);
	
//start time
	//chronometer();    
	
//run the breakdown graph
	runBreakdown(false); 
	
//grab the users date range preferences
	$time = grab_timeframe(); 
	$mysql['to'] = mysql_real_escape_string($time['to']);
	$mysql['from'] = mysql_real_escape_string($time['from']); 
	
	
//show real or filtered clicks
	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT user_pref_show, user_cpc_or_cpv FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
	$user_result = _mysql_query($user_sql, $dbGlobalLink); //($user_sql);
	$user_row = mysql_fetch_assoc($user_result);	
	
	if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
	if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
	if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
	if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; } 
	
	if ($user_row['user_cpc_or_cpv'] == 'cpv')  $cpv = true;
	else 										$cpv = false; ?>
	
	
	
<h3 class="green overview-spacer">Account Overview</h3>    
<table cellpadding="0" cellspacing="1" class="m-stats">
	<tr>  
		<th colspan="2" >Campaign / Advanced LP</th>
		<th>Clicks</th> 
		<th>Leads</th>
		<th>S/U</th>
		<th>Payout</th>
		<th>EPC</th> 
		<th>CPC</th>
		<th>Income</th>
		<th>Cost</th>
		<th>Net</th>
		<th>ROI</th>
	</tr> <?                        
				
	


//grab the affiliate campaigns to display    

//ok, if x=1, show non ALP stuff, if x=2, show advanced landing page stuff
for($x = 0; $x < 2; $x++) { 

	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	
	if ($x == 0) {
		
		//select regular setup
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
	
	} else {		 
		
		$alp_counter=1;
		
		//select advanced landing page setup
		$info_sql = " 
				SELECT     	202_landing_pages.landing_page_id,
							landing_page_nickname
				 FROM      	202_summary_overview LEFT JOIN 202_landing_pages USING (landing_page_id)
				 WHERE   	202_landing_pages.user_id='".$mysql['user_id']."'
				 AND        	202_landing_pages.landing_page_deleted = 0
				 AND        	202_summary_overview.click_time >= ".$mysql['from']."
				 AND        	202_summary_overview.click_time < ".$mysql['to']."
				 AND 		202_landing_pages.landing_page_id!=0 
				 GROUP BY  landing_page_id 
				 ORDER BY  202_landing_pages.landing_page_nickname ASC"; 
	} 
		
	
	$info_result = _mysql_query($info_sql) ; //($info_sql);      

	while ($info_row = mysql_fetch_array($info_result, MYSQL_ASSOC)) {
	
	//mysql escape the vars
		$mysql['aff_campaign_id'] = mysql_real_escape_string($info_row['aff_campaign_id']);
		$mysql['landing_page_id'] = mysql_real_escape_string($info_row['landing_page_id']);

	//grab the variables
		if ($x ==0) {
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
		}
		else {
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
					AND 2c.landing_page_id='".$mysql['landing_page_id']."'
					AND 2c.click_time > ".$mysql['from'] ."
					AND 2c.click_time <= ".$mysql['to']."
					AND 2c.click_alp=1
			";
		}
		$click_result = _mysql_query($click_sql) ; //($click_sql);
		$click_row = mysql_fetch_assoc($click_result);

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
					
		$total_roi = @round($total_net/$total_cost*100);    
		
	//html escape vars
		$html['clicks'] = htmlentities($clicks, ENT_QUOTES, 'UTF-8');
		$html['leads'] = htmlentities($leads, ENT_QUOTES, 'UTF-8');  
		$html['su_ratio'] = htmlentities($su_ratio.'%', ENT_QUOTES, 'UTF-8');  
		$html['payout'] = htmlentities(dollar_format($payout), ENT_QUOTES, 'UTF-8');  
		$html['epc'] = htmlentities(dollar_format($epc), ENT_QUOTES, 'UTF-8');  
		$html['avg_cpc'] = htmlentities(dollar_format($avg_cpc, $cpv), ENT_QUOTES, 'UTF-8');  
		$html['income'] = htmlentities(dollar_format($income), ENT_QUOTES, 'UTF-8');  
		$html['cost'] = htmlentities(dollar_format($cost, $cpv), ENT_QUOTES, 'UTF-8');  
		$html['net'] = htmlentities(dollar_format($net, $cpv), ENT_QUOTES, 'UTF-8');  
		$html['roi'] = htmlentities($roi.'%', ENT_QUOTES, 'UTF-8');  
		
		$html['aff_campaign_id'] = htmlentities($info_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');
		$html['aff_campaign_name'] = htmlentities($info_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
		$html['aff_campaign_payout'] = htmlentities($info_row['aff_campaign_payout'], ENT_QUOTES, 'UTF-8');
		$html['aff_network_name'] = htmlentities($info_row['aff_network_name'], ENT_QUOTES, 'UTF-8');
	
		$html['landing_page_id'] = htmlentities($info_row['landing_page_id'], ENT_QUOTES, 'UTF-8');
		$html['landing_page_nickname'] = htmlentities($info_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
	
		
	//shorten campaign name
		if (strlen($html['aff_campaign_name']) > 20) {
			$html['aff_campaign_name'] = substr($html['aff_campaign_name'],0,20) . '...';   
		} 
		
		if (strlen($html['landing_page_nickname']) > 20) {
			$html['landing_page_nickname'] = substr($html['landing_page_nickname'],0,20) . '...';   
		}
		?>
   
	<? if ($alp_counter == 1) { $alp_counter++; /*?>
		<tr>
			<td colspan="12"><hr/></td>
		</tr>
	<?*/ } ?>
		
	<tr>
		<? if ($x == 0) { ?>
			<td class="m-row2"><? echo $html['aff_network_name']; ?></td>
			<td class="m-row2 m-row2-fade" ><? printf('<a href="#aff_%s" style="color: #000000;">%s</a>', $html['aff_campaign_id'], $html['aff_campaign_name']); ?></td>
		<? } else { ?>
			<td class="m-row2">Advanced LP</td>
			<td class="m-row2 m-row2-fade"><? printf('<a href="#lp_%s" style="color: #000000;">%s</a>', $html['landing_page_id'], $html['landing_page_nickname']); ?></td>
		<? } ?>
		<td class="m-row1"><? echo $html['clicks']; ?></td>
		<td class="m-row1"><? echo $html['leads']; ?></td> 
		<td class="m-row1"><? echo  $html['su_ratio']; ?></td>
		<td class="m-row1"><? if ($x==0) { echo $html['payout']; } ?></td> 
		<td class="m-row3"><? echo $html['epc']; ?></td>
		<td class="m-row3"><? echo $html['avg_cpc']; ?></td>
		<td class="m-row4 "><? echo $html['income']; ?></td>
		<td class="m-row4 ">(<? echo $html['cost']; ?>)</td>
		<td class="<? if ($net > 0) { echo 'm-row_pos'; } elseif ($net < 0) { echo 'm-row_neg'; } else { echo 'm-row_zero'; } ?>"><? echo $html['net'] ; ?></td>
		<td class="<? if ($net > 0) { echo 'm-row_pos'; } elseif ($net < 0) { echo 'm-row_neg'; } else { echo 'm-row_zero'; } ?>"><? echo $html['roi'] ; ?></td>
	</tr> 
	
	<? //OK NOW if this is an advanced landing page, u just showed the stats, but go through again and gata all the data now for the individual ones
	if ($x == 1) {
		
		
		$info_sql2 = "
				SELECT     	202_aff_campaigns.aff_campaign_id,
							aff_campaign_name,
							aff_campaign_payout,
							aff_network_name
				 FROM      	202_summary_overview LEFT JOIN 202_aff_campaigns USING (aff_campaign_id) LEFT JOIN 202_aff_networks USING(aff_network_id) 
				 WHERE   	202_aff_networks.user_id='".$mysql['user_id']."'
				 AND        	202_aff_networks.aff_network_deleted = 0
				 AND        	202_aff_campaigns.aff_campaign_deleted = 0 
				 AND        	202_summary_overview.click_time >= ".$mysql['from']."
				 AND        	202_summary_overview.click_time < ".$mysql['to']."
				 AND 		landing_page_id='".$mysql['landing_page_id']."' 
				 GROUP BY  202_aff_campaigns.aff_campaign_id 
				 ORDER BY  202_aff_networks.aff_network_name ASC,
							202_aff_campaigns.aff_campaign_name ASC"; 
		$info_result2 = _mysql_query($info_sql2) ; //($info_sql2);      
		while ($info_row2 = mysql_fetch_array($info_result2, MYSQL_ASSOC)) {
		
			//mysql escape the vars
				$mysql['aff_campaign_id'] = mysql_real_escape_string($info_row2['aff_campaign_id']);
				
			//grab the variables
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
						AND aff_campaign_id='".$mysql['aff_campaign_id']."'
						AND landing_page_id='".$mysql['landing_page_id']."'
						AND click_time > ".$mysql['from'] ."
						AND click_time <= ".$mysql['to']."
						AND 2c.click_alp=1
				";
				$click_result = _mysql_query($click_sql);
				$click_row = mysql_fetch_assoc($click_result);
		
			//get the stats
				$clicks = 0;  
				$clicks = $click_row['clicks'];
			
			//avg cpc and cost    
				$avg_cpc = 0;
				$avg_cpc = $click_row['avg_cpc']; 
				
				$cost = 0;
				$cost = $clicks * $avg_cpc; 
		
			//leads
				$leads = 0;
				$leads = $click_row['leads'];
				
			//signup ratio
				$su_ratio - 0;
				$su_ratio = @round($leads/$clicks*100,2);
		
			//current payout
				$payout = 0;
				$payout = $info_row2['aff_campaign_payout'];
		
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
				$roi = @@round($net/$cost*100);    
							
			//html escape vars
				$html['clicks'] = htmlentities($clicks, ENT_QUOTES, 'UTF-8');
				$html['leads'] = htmlentities($leads, ENT_QUOTES, 'UTF-8');  
				$html['su_ratio'] = htmlentities($su_ratio.'%', ENT_QUOTES, 'UTF-8');  
				$html['payout'] = htmlentities(dollar_format($payout), ENT_QUOTES, 'UTF-8');  
				$html['epc'] = htmlentities(dollar_format($epc), ENT_QUOTES, 'UTF-8');  
				$html['avg_cpc'] = htmlentities(dollar_format($avg_cpc, $cpv), ENT_QUOTES, 'UTF-8');  
				$html['income'] = htmlentities(dollar_format($income), ENT_QUOTES, 'UTF-8');  
				$html['cost'] = htmlentities(dollar_format($cost, $cpv), ENT_QUOTES, 'UTF-8');  
				$html['net'] = htmlentities(dollar_format($net, $cpv), ENT_QUOTES, 'UTF-8');  
				$html['roi'] = htmlentities($roi.'%', ENT_QUOTES, 'UTF-8');  
				
				$html['aff_campaign_id'] = htmlentities($info_row2['aff_campaign_id'], ENT_QUOTES, 'UTF-8');
				$html['aff_campaign_name'] = htmlentities($info_row2['aff_campaign_name'], ENT_QUOTES, 'UTF-8');
				$html['aff_campaign_payout'] = htmlentities($info_row2['aff_campaign_payout'], ENT_QUOTES, 'UTF-8');
				$html['aff_network_name'] = htmlentities($info_row2['aff_network_name'], ENT_QUOTES, 'UTF-8');
			
				$html['landing_page_id'] = htmlentities($info_row2['landing_page_id'], ENT_QUOTES, 'UTF-8');
				$html['landing_page_nickname'] = htmlentities($info_row2['landing_page_nickname'], ENT_QUOTES, 'UTF-8');
			
				
			//shorten campaign name
				if (strlen($html['aff_campaign_name']) > 20) {
					$html['aff_campaign_name'] = substr($html['aff_campaign_name'],0,20) . '...';   
				} 
				
				if (strlen($html['landing_page_nickname']) > 20) {
					$html['landing_page_nickname'] = substr($html['landing_page_nickname'],0,20) . '...';   
				}
				
				?><tr>
					<td class="m-row2" style="padding-left: 20px;"> - <? echo $html['aff_network_name']; ?></td>
					<td class="m-row2 m-row2-fade"  style="padding-left: 20px;"> - <? echo $html['aff_campaign_name']; ?></td>
					<td class="m-row1"><? echo $html['clicks']; ?></td>
					<td class="m-row1"><? echo $html['leads']; ?></td> 
					<td class="m-row1"><? echo  $html['su_ratio']; ?></td>
					<td class="m-row1"><? { echo $html['payout']; } ?></td> 
					<td class="m-row3"><? echo $html['epc']; ?></td>
					<td class="m-row3"></td>
					<td class="m-row4 "><? echo $html['income']; ?></td>
					<td class="m-row4 "></td>
					<td class="m-row_zero"></td>
					<td class="m-row_zero"></td>
				</tr><?
			
			}
		}	
	}		
}
	

$html['total_clicks'] = htmlentities($total_clicks, ENT_QUOTES, 'UTF-8');
$html['total_leads'] = htmlentities($total_leads, ENT_QUOTES, 'UTF-8');  
$html['total_su_ratio'] = htmlentities($total_su_ratio.'%', ENT_QUOTES, 'UTF-8');  
$html['total_epc'] = htmlentities(dollar_format($total_epc), ENT_QUOTES, 'UTF-8');  
$html['total_avg_cpc'] = htmlentities(dollar_format($total_avg_cpc, $cpv), ENT_QUOTES, 'UTF-8');  
$html['total_income'] = htmlentities(dollar_format($total_income), ENT_QUOTES, 'UTF-8');  
$html['total_cost'] = htmlentities(dollar_format($total_cost, $cpv), ENT_QUOTES, 'UTF-8');  
$html['total_net'] = htmlentities(dollar_format($total_net, $cpv), ENT_QUOTES, 'UTF-8');  
$html['total_roi'] = htmlentities($total_roi.'%', ENT_QUOTES, 'UTF-8');  ?>

	<tr>
		<td class="m-row2 m-row-bottom " colspan="2"><strong>Totals for report</strong></td>
		<td class="m-row1 m-row-bottom"><strong><? echo $html['total_clicks']; ?></strong></td>
		<td class="m-row1 m-row-bottom"><strong><? echo $html['total_leads']; ?></strong></td>
		<td class="m-row1 m-row-bottom"><strong><? echo $html['total_su_ratio']; ?></strong></td>      
		<td class="m-row1 m-row-bottom"/>    
		<td class="m-row3 m-row-bottom"><strong><? echo $html['total_epc']; ?></strong></td>      
		<td class="m-row3 m-row-bottom"><strong><? echo $html['total_avg_cpc']; ?></strong></td>      
		<td class="m-row4 m-row-bottom"><strong><? echo $html['total_income']; ?></strong></td>
		<td class="m-row4 m-row-bottom"><strong>(<? echo $html['total_cost']; ?>)</strong></td>
		<td class="<? if ($total_net > 0) { echo 'm-row_pos'; } elseif ($total_net < 0) { echo 'm-row_neg'; } else { echo 'm-row_zero'; } ?> m-row-bottom"><strong><? echo $html['total_net']; ?></strong></td>
		<td class="<? if ($total_net > 0) { echo 'm-row_pos'; } elseif ($total_net < 0) { echo 'm-row_neg'; } else { echo 'm-row_zero'; } ?> m-row-bottom"><strong><? echo $html['total_roi']; ?></strong></td>
	</tr>
</table> <?     











/*  BELOW IS ALMOST THE EXACT SAME CODE 
	AS THE ABOVE, BUT IT DOES IT PER EACH 
	AFFILIATE CAMPAIGN AND BREAKS IT DOWN 
	PER PPC ACCOUNT */



	



//ok, if x=1, show non ALP stuff, if x=2, show advanced landing page stuff
for($x = 0; $x < 2; $x++) { 

	$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
	
	if ($x == 0) {
		
		//select regular setup
		$info_sql = " 
				SELECT     	202_aff_campaigns.aff_campaign_id,
							aff_campaign_name,
							aff_campaign_payout,
							aff_network_name
				 FROM      	202_summary_overview LEFT JOIN 202_aff_campaigns USING (aff_campaign_id) LEFT JOIN 202_aff_networks USING(aff_network_id) 
				 WHERE   	202_aff_networks.user_id='".$mysql['user_id']."'
				 AND        	202_aff_networks.aff_network_deleted = 0
				 AND        	202_aff_campaigns.aff_campaign_deleted = 0 
				 AND        	202_summary_overview.click_time >= ".$mysql['from']."
				 AND        	202_summary_overview.click_time < ".$mysql['to']."
				 AND 		landing_page_id=0 
				 GROUP BY  aff_campaign_id 
				 ORDER BY  202_aff_networks.aff_network_name ASC,
							202_aff_campaigns.aff_campaign_name ASC"; 
	
	} else {		 
		
		$alp_counter=1;
		
		//select advanced landing page setup
		$info_sql = " 
				SELECT     	202_landing_pages.landing_page_id,
							landing_page_nickname
				 FROM      	202_summary_overview LEFT JOIN 202_landing_pages USING (landing_page_id)
				 WHERE   	202_landing_pages.user_id='".$mysql['user_id']."'
				 AND        	202_landing_pages.landing_page_deleted = 0
				 AND        	202_summary_overview.click_time >= ".$mysql['from']."
				 AND        	202_summary_overview.click_time < ".$mysql['to']."
				 AND 		202_landing_pages.landing_page_id!=0 
				 GROUP BY  landing_page_id 
				 ORDER BY  202_landing_pages.landing_page_nickname ASC"; 
	} 

	
	$info_result = _mysql_query($info_sql) ; //($info_sql);  
	while ($info_row = mysql_fetch_array($info_result, MYSQL_ASSOC)) {
		
			$total_clicks=0;
		$total_leads=0;
		$total_su_ratio=0;
		$total_epc=0;
		$total_avg_cpc=0;
		$total_income=0;
		$total_cost=0;
		$total_net=0;
		$total_roi=0;
		
	//html escape variables
		$html['aff_campaign_id'] = htmlentities($info_row['aff_campaign_id'], ENT_QUOTES, 'UTF-8');
		$html['aff_campaign_name'] = htmlentities($info_row['aff_campaign_name'], ENT_QUOTES, 'UTF-8'); 
		
		$html['landing_page_id'] = htmlentities($info_row['landing_page_id'], ENT_QUOTES, 'UTF-8');
		$html['landing_page_nickname'] = htmlentities($info_row['landing_page_nickname'], ENT_QUOTES, 'UTF-8');  ?>
	
	<? if ($x==0) { ?><h3 class="green overview-spacer" id="aff_<? echo $html['aff_campaign_id']; ?>"><? echo $html['aff_campaign_name']; ?> <span style="font-size: 65%; color: grey; font-weight: normal;">[direct link &amp; simple lp]</span></h3>
	<? } else { ?><h3 class="green overview-spacer" id="lp_<? echo $html['landing_page_id']; ?>"><? echo $html['landing_page_nickname']; ?> <span style="font-size: 65%; color: grey; font-weight: normal;">[adv lp]</span></h3><? } ?>
	<table cellpadding="0" cellspacing="1" class="m-stats"> 
		<tr class="stats-grey">   
			<th colspan="2">PPC Account</th>
			<th>Clicks</th> 
			<th>Leads</th>
			<th>S/U</th>
			<th>Payout</th>
			<th>EPC</th> 
			<th>Avg CPC</th>
			<th>Income</th>
			<th>Cost</th>
			<th>Net</th>
			<th>ROI</th>
		</tr> <?
		
	//ON THE FIRST RUN, GET THE TOTAL OF NO PPC ACCOUNTS, and then FOR THE INDIV PPC ACCOUNTS	
		//mysql escape the vars
			$mysql['aff_campaign_id'] = mysql_real_escape_string($info_row['aff_campaign_id']);
			$mysql['landing_page_id'] = mysql_real_escape_string($info_row['landing_page_id']);

		//grab the variables                                                        
			if ($x ==0) {
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
						AND aff_campaign_id='".$mysql['aff_campaign_id']."'
						AND ppc_account_id='0'
						AND click_time > ".$mysql['from'] ."
						AND click_time <= ".$mysql['to']."
						AND 2c.click_alp=0
				";
			} else {
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
						AND landing_page_id='".$mysql['landing_page_id']."'
						AND ppc_account_id='0'
						AND click_time > ".$mysql['from'] ."
						AND click_time <= ".$mysql['to']."
						AND 2c.click_alp=1
				";
			}
			$click_result = _mysql_query($click_sql) ; //($click_sql);
			$click_row = mysql_fetch_assoc($click_result);
			
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
			$roi = @round($net/$cost*100);    
												  
			$total_roi = @round($total_net/$total_cost*100);   
			 
			
		//html escape vars
			$html['clicks'] = htmlentities($clicks, ENT_QUOTES, 'UTF-8');
			$html['leads'] = htmlentities($leads, ENT_QUOTES, 'UTF-8');  
			$html['su_ratio'] = htmlentities($su_ratio.'%', ENT_QUOTES, 'UTF-8');  
			$html['payout'] = htmlentities(dollar_format($payout), ENT_QUOTES, 'UTF-8');  
			$html['epc'] = htmlentities(dollar_format($epc), ENT_QUOTES, 'UTF-8');  
			$html['avg_cpc'] = htmlentities(dollar_format($avg_cpc, $cpv), ENT_QUOTES, 'UTF-8');  
			$html['income'] = htmlentities(dollar_format($income), ENT_QUOTES, 'UTF-8');  
			$html['cost'] = htmlentities(dollar_format($cost, $cpv), ENT_QUOTES, 'UTF-8');  
			$html['net'] = htmlentities(dollar_format($net, $cpv), ENT_QUOTES, 'UTF-8');  
			$html['roi'] = htmlentities($roi.'%', ENT_QUOTES, 'UTF-8');  
			
			$html['ppc_account_name'] = htmlentities($info_row2['ppc_account_name'], ENT_QUOTES, 'UTF-8');
			$html['aff_campaign_payout'] = htmlentities($info_row['aff_campaign_payout'], ENT_QUOTES, 'UTF-8');
		
			$ppc_network_icon = pcc_network_icon($info_row2['ppc_network_name'],$info_row2['ppc_account_name']); 
		
		//shorten campaign name
			if (strlen($html['ppc_account_name']) > 20) {
				$html['ppc_account_name'] = substr($html['ppc_account_name'],0,20) . '...';   
			} ?>
	   
		<? if ($clicks >0) { ?>
			
		<tr>
			<td class="m-row2 m-row-small grey"><? echo $ppc_network_icon; ?></td>
			<td class="m-row2 m-row2-fade " >[no ppc referer]</td>  
			<td class="m-row1"><? echo $html['clicks']; ?></td>
			<td class="m-row1"><? echo $html['leads']; ?></td> 
			<td class="m-row1"><? echo  $html['su_ratio']; ?></td>
			<td class="m-row1"><? echo $html['payout']; ?></td> 
			<td class="m-row3"><? echo $html['epc']; ?></td>
			<td class="m-row3"><? echo $html['avg_cpc']; ?></td>
			<td class="m-row4 "><? echo $html['income']; ?></td>
			<td class="m-row4 ">(<? echo $html['cost']; ?>)</td>
			<td class="<? if ($net > 0) { echo 'm-row_pos'; } elseif ($net < 0) { echo 'm-row_neg'; } else { echo 'm-row_zero'; } ?>"><? echo $html['net'] ; ?></td>
			<td class="<? if ($net > 0) { echo 'm-row_pos'; } elseif ($net < 0) { echo 'm-row_neg'; } else { echo 'm-row_zero'; } ?>"><? echo $html['roi'] ; ?></td>
		</tr> 
		
		<? }
		
	//mysql escape the variables
		$mysql['aff_campaign_id'] = mysql_real_escape_string($info_row['aff_campaign_id']);    
			
	//grab theppc accounts to display
		if ($x == 0) {
			//normal campaign
			$info_sql2 = "SELECT    aff_campaign_id,
								202_ppc_accounts.ppc_account_id,
								ppc_account_name,
								ppc_network_name
					  FROM      202_summary_overview 
					  LEFT JOIN 202_ppc_accounts ON (202_ppc_accounts.ppc_account_id = 202_summary_overview.ppc_account_id) 
					  LEFT JOIN 202_ppc_networks USING (ppc_network_id)
					  WHERE     202_ppc_networks.ppc_network_deleted=0
					  AND       202_ppc_accounts.ppc_account_deleted=0
					  AND       202_summary_overview.aff_campaign_id = ".$mysql['aff_campaign_id']."
					  AND       202_summary_overview.click_time >= ".$mysql['from']."
					  AND       202_summary_overview.click_time < ".$mysql['to']."
					  GROUP BY  ppc_account_id
					  ORDER BY  202_ppc_networks.ppc_network_name ASC,
								 202_ppc_accounts.ppc_account_name ASC"; 
		} else {
			//advance landing page 
			$mysql['landing_page_id'] = mysql_real_escape_string($info_row['landing_page_id']);
			$info_sql2 = " 
				SELECT    		 202_landing_pages.landing_page_id,
								202_ppc_accounts.ppc_account_id,
								ppc_account_name,
								ppc_network_name
				 FROM      	202_summary_overview 
				 LEFT JOIN 202_landing_pages ON (202_landing_pages.landing_page_id = 202_summary_overview.landing_page_id)  
				 LEFT JOIN 202_ppc_accounts ON (202_ppc_accounts.ppc_account_id = 202_summary_overview.ppc_account_id)
				 LEFT JOIN 202_ppc_networks USING (ppc_network_id) 
				 WHERE   	202_ppc_networks.ppc_network_deleted=0
				 AND          202_ppc_accounts.ppc_account_deleted=0
				 AND          202_landing_pages.user_id='".$mysql['user_id']."'
				 AND        	202_landing_pages.landing_page_deleted = 0
				 AND        	202_summary_overview.click_time >= ".$mysql['from']."
				 AND        	202_summary_overview.click_time < ".$mysql['to']."
				 AND 		202_landing_pages.landing_page_id!=0
				 AND 		202_landing_pages.landing_page_id='".$mysql['landing_page_id']."'
				 GROUP BY  202_ppc_accounts.ppc_account_id
				ORDER BY   202_ppc_networks.ppc_network_name ASC,
						      202_ppc_accounts.ppc_account_name ASC"; 
			
			

		}
		$info_result2 = _mysql_query($info_sql2) ; //($info_sql2); 

		while ($info_row2 = mysql_fetch_array($info_result2, MYSQL_ASSOC)) {
			
			
	
		//mysql escape the vars
			$mysql['aff_campaign_id'] = mysql_real_escape_string($info_row2['aff_campaign_id']);
			$mysql['landing_page_id'] = mysql_real_escape_string($info_row2['landing_page_id']);
			$mysql['ppc_account_id'] = mysql_real_escape_string($info_row2['ppc_account_id']);

		//grab the variables                                                        
			if ($x ==0) {
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
						AND aff_campaign_id='".$mysql['aff_campaign_id']."'
						AND ppc_account_id='".$mysql['ppc_account_id']."'
						AND click_time > ".$mysql['from'] ."
						AND click_time <= ".$mysql['to']."
						AND 2c.click_alp=0
				";
			} else {
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
						AND aff_campaign_id='".$mysql['aff_campaign_id']."'
						AND ppc_account_id='".$mysql['ppc_account_id']."'
						AND click_time > ".$mysql['from'] ."
						AND click_time <= ".$mysql['to']."
						AND 2c.click_alp=1
				";
			}
			$click_result = _mysql_query($click_sql) ; 
			$click_row = mysql_fetch_assoc($click_result);
			
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
			$total_avg_cpc = @round($total_cost/$total_clicks,2);

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
			$roi = @round($net/$cost*100);    
												  
			$total_roi = @round($total_net/$total_cost*100);   
			 
			
		//html escape vars
			$html['clicks'] = htmlentities($clicks, ENT_QUOTES, 'UTF-8');
			$html['leads'] = htmlentities($leads, ENT_QUOTES, 'UTF-8');  
			$html['su_ratio'] = htmlentities($su_ratio.'%', ENT_QUOTES, 'UTF-8');  
			$html['payout'] = htmlentities(dollar_format($payout), ENT_QUOTES, 'UTF-8');  
			$html['epc'] = htmlentities(dollar_format($epc), ENT_QUOTES, 'UTF-8');  
			$html['avg_cpc'] = htmlentities(dollar_format($avg_cpc, $cpv), ENT_QUOTES, 'UTF-8');  
			$html['income'] = htmlentities(dollar_format($income), ENT_QUOTES, 'UTF-8');  
			$html['cost'] = htmlentities(dollar_format($cost, $cpv), ENT_QUOTES, 'UTF-8');  
			$html['net'] = htmlentities(dollar_format($net, $cpv), ENT_QUOTES, 'UTF-8');  
			$html['roi'] = htmlentities($roi.'%', ENT_QUOTES, 'UTF-8');  
			
			$html['ppc_account_name'] = htmlentities($info_row2['ppc_account_name'], ENT_QUOTES, 'UTF-8');
			$html['aff_campaign_payout'] = htmlentities($info_row['aff_campaign_payout'], ENT_QUOTES, 'UTF-8');
		
			$ppc_network_icon = pcc_network_icon($info_row2['ppc_network_name'],$info_row2['ppc_account_name']); 
		
		//shorten campaign name
			if (strlen($html['ppc_account_name']) > 20) {
				$html['ppc_account_name'] = substr($html['ppc_account_name'],0,20) . '...';   
			} ?>
	   
		<tr>
			<td class="m-row2 m-row-small grey"><? echo $ppc_network_icon; ?></td>
			<td class="m-row2 m-row2-fade " ><? echo $html['ppc_account_name']; ?></td>  
			<td class="m-row1"><? echo $html['clicks']; ?></td>
			<td class="m-row1"><? echo $html['leads']; ?></td> 
			<td class="m-row1"><? echo  $html['su_ratio']; ?></td>
			<td class="m-row1"><? echo $html['payout']; ?></td> 
			<td class="m-row3"><? echo $html['epc']; ?></td>
			<td class="m-row3"><? echo $html['avg_cpc']; ?></td>
			<td class="m-row4 "><? echo $html['income']; ?></td>
			<td class="m-row4 ">(<? echo $html['cost']; ?>)</td>
			<td class="<? if ($net > 0) { echo 'm-row_pos'; } elseif ($net < 0) { echo 'm-row_neg'; } else { echo 'm-row_zero'; } ?>"><? echo $html['net'] ; ?></td>
			<td class="<? if ($net > 0) { echo 'm-row_pos'; } elseif ($net < 0) { echo 'm-row_neg'; } else { echo 'm-row_zero'; } ?>"><? echo $html['roi'] ; ?></td>
		</tr> 
		
	<?  }                                                                    
				
	$html['total_clicks'] = htmlentities($total_clicks, ENT_QUOTES, 'UTF-8');
	$html['total_leads'] = htmlentities($total_leads, ENT_QUOTES, 'UTF-8');  
	$html['total_su_ratio'] = htmlentities($total_su_ratio.'%', ENT_QUOTES, 'UTF-8');  
	$html['total_epc'] = htmlentities(dollar_format($total_epc), ENT_QUOTES, 'UTF-8');  
	$html['total_avg_cpc'] = htmlentities(dollar_format($total_avg_cpc, $cpv), ENT_QUOTES, 'UTF-8');  
	$html['total_income'] = htmlentities(dollar_format($total_income), ENT_QUOTES, 'UTF-8');  
	$html['total_cost'] = htmlentities(dollar_format($total_cost, $cpv), ENT_QUOTES, 'UTF-8');  
	$html['total_net'] = htmlentities(dollar_format($total_net, $cpv), ENT_QUOTES, 'UTF-8');  
	$html['total_roi'] = htmlentities($total_roi.'%', ENT_QUOTES, 'UTF-8');  ?>

		<tr>
			<td class="m-row2 m-row-bottom" colspan="2"><strong>Totals for report</strong></td>
			<td class="m-row1 m-row-bottom"><strong><? echo $html['total_clicks']; ?></strong></td>
			<td class="m-row1 m-row-bottom"><strong><? echo $html['total_leads']; ?></strong></td>
			<td class="m-row1 m-row-bottom"><strong><? echo $html['total_su_ratio']; ?></strong></td>      
			<td class="m-row1 m-row-bottom"/>    
			<td class="m-row3 m-row-bottom"><strong><? echo $html['total_epc']; ?></strong></td>      
			<td class="m-row3 m-row-bottom"><strong><? echo $html['total_avg_cpc']; ?></strong></td>      
			<td class="m-row4 m-row-bottom"><strong><? echo $html['total_income']; ?></strong></td>
			<td class="m-row4 m-row-bottom"><strong>(<? echo $html['total_cost']; ?>)</strong></td>
			<td class="<? if ($total_net > 0) { echo 'm-row_pos'; } elseif ($total_net < 0) { echo 'm-row_neg'; } else { echo 'm-row_zero'; } ?> m-row-bottom"><strong><? echo $html['total_net']; ?></strong></td>
			<td class="<? if ($total_net > 0) { echo 'm-row_pos'; } elseif ($total_net < 0) { echo 'm-row_neg'; } else { echo 'm-row_zero'; } ?> m-row-bottom"><strong><? echo $html['total_roi']; ?></strong></td>
		</tr>
	</table>
<? }  

}?>

