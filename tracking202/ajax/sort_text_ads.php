<?php include_once($_SERVER['DOCUMENT_ROOT'] . '/202-config/connect.php');

AUTH::require_user();


//set the timezone for the user, for entering their dates.
	AUTH::set_timezone($_SESSION['user_timezone']);

//grab user time range preference
	$time = grab_timeframe();
	$mysql['to'] = $db->real_escape_string($time['to']);
	$mysql['from'] = $db->real_escape_string($time['from']);


//show real or filtered clicks
	$mysql['user_id'] = $db->real_escape_string($_SESSION['user_id']);
	$user_sql = "SELECT user_pref_breakdown, user_pref_show, user_cpc_or_cpv FROM 202_users_pref WHERE user_id=".$mysql['user_id'];
	$user_result = _mysqli_query($user_sql, $dbGlobalLink); //($user_sql);
	$user_row = $user_result->fetch_assoc();
	$breakdown = $user_row['user_pref_breakdown'];

	if ($user_row['user_pref_show'] == 'all') { $click_flitered = ''; }
	if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
	if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
	if ($user_row['user_pref_show'] == 'filtered_bot') { $click_filtered = " AND click_bot='1' "; }
	if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; }

	if ($user_row['user_cpc_or_cpv'] == 'cpv')  $cpv = true;
	else 										$cpv = false;


		//lets build the new keyword list
			$db_table = '2c';
			$query = query('
						SELECT 
							2ta.text_ad_name, 
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
						LEFT OUTER JOIN 202_text_ads AS 2ta ON (2ca.text_ad_id = 2ta.text_ad_id)
			', $db_table, true, true, false, " $click_filtered GROUP BY 2ta.text_ad_id ORDER BY clicks DESC", $_POST['offset'], true, true);
			$info_sql = $query['click_sql'];
			$info_result = $db->query($info_sql) or record_mysql_error($info_sql); 
			$total_rows = $info_result->num_rows;
			$html['from'] = htmlentities($query['from'], ENT_QUOTES, 'UTF-8');
			$html['to'] = htmlentities($query['to'], ENT_QUOTES, 'UTF-8'); 
			$html['rows'] = htmlentities($query['rows'], ENT_QUOTES, 'UTF-8');
			
			?>

			<div class="row" style="margin-top: 10px;">
				<div class="col-xs-6">
					<span class="infotext"><?php printf('<div class="results">Results <b>%s - %s</b> of <b>%s</b></div>',$html['from'],$html['to'],$html['rows']); ?></span>
				</div>
				<div class="col-xs-6 text-right" style="top: -10px;">
					<img style="margin-bottom:2px;" src="/202-img/icons/16x16/page_white_excel.png"/>
					<a style="font-size:12px;" target="_new" href="/tracking202/analyze/text_ads_download.php">
						<strong>Download to excel</strong>
					</a>
				</div>
			</div>

			<div class="row">
			<div class="col-xs-12" style="margin-top: 10px;">
			<table class="table table-bordered table-hover" id="stats-table">
				<thead>
				<tr style="background-color: #f2fbfa;">
					<th colspan="2" style="text-align:left">Text ad</th>
					<th>Clicks</th>
					<th>Click Throughs</th>
					<th>LP CTR</th>
					<th>Leads</th>
					<th>S/U</th>
					<th>Payout</th>
					<th>EPC</th>
					<th>Avg CPC</th>
					<th>Income</th>
					<th>Cost</th>
					<th>Net</th>
					<th>ROI</th>
				</tr>
				</thead>
				<tbody>

			<?php

				//to show "0" when no stats	
				$total_clicks = 0;
				$total_click_throughs = 0;
				$total_leads = 0;

			if ($total_rows > 0) {

				while ($click_row = $info_result->fetch_assoc()) {
					if (!$click_row['text_ad_name']) {
						$html['text_ad_name'] = '[no text ad]';
					} else {
						$html['text_ad_name'] = htmlentities($click_row['text_ad_name'], ENT_QUOTES, 'UTF-8');
					}

					//grab the variables
						$clicks = 0;
						$clicks = $click_row['clicks'];

						$total_clicks = $total_clicks + $clicks;

						$click_throughs = 0;
						$click_throughs = $click_row['click_out'];

						$total_click_throughs = $total_click_throughs + $click_throughs;

					//ctr rate
						$ctr_ratio = 0;
						$ctr_ratio = @round($click_throughs/$clicks*100,2);

						$total_ctr_ratio = @round($total_click_throughs/$total_clicks*100,2);

					//cost
						$cost = 0;
						$cost = $click_row['cost'];

						$total_cost = $total_cost + $cost;

					//avg cpc and cost
						$avg_cpc = 0;
						$avg_cpc = $click_row['avg_cpc'];

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
						$payout = $click_row['payout'];

						$total_payout = $total_payout + $click_row['payout'];

					//income
						$income = 0;
						$income = $click_row['income'];

						$total_income = $total_income + $income;

					//grab the EPC
						$epc = 0;
						$epc = @round($income/$clicks,2);

						$total_epc = @@round($total_income/$total_clicks,2);

					//net income
						$net = 0;
						$net = $income - $cost;

						$total_net = $total_income - $total_cost;

					//roi
						$roi = 0;
						$roi = @round($net/$cost*100);

						$total_roi = @round(($total_net/$total_cost*100),2); 


						$html['sort_keyword_clicks'] = htmlentities(number_format($clicks), ENT_QUOTES, 'UTF-8');
						$html['sort_keyword_click_throughs'] = htmlentities(number_format($click_throughs), ENT_QUOTES, 'UTF-8');
						$html['sort_keyword_ctr'] = htmlentities($ctr_ratio . '%', ENT_QUOTES, 'UTF-8');
						$html['sort_keyword_leads'] = htmlentities($leads, ENT_QUOTES, 'UTF-8');
						$html['sort_keyword_su_ratio'] = htmlentities($su_ratio.'%', ENT_QUOTES, 'UTF-8');
						$html['sort_keyword_payout'] = htmlentities(dollar_format($payout), ENT_QUOTES, 'UTF-8');
						$html['sort_keyword_epc'] = htmlentities(dollar_format($epc), ENT_QUOTES, 'UTF-8');
						$html['sort_keyword_avg_cpc'] = htmlentities(dollar_format($avg_cpc, $cpv), ENT_QUOTES, 'UTF-8');
						$html['sort_keyword_income'] = htmlentities(dollar_format($income, $cpv), ENT_QUOTES, 'UTF-8');
						$html['sort_keyword_cost'] = htmlentities(dollar_format($cost, $cpv), ENT_QUOTES, 'UTF-8');
						$html['sort_keyword_net'] = htmlentities(dollar_format($net, $cpv), ENT_QUOTES, 'UTF-8');
						$html['sort_keyword_roi'] = htmlentities($roi.'%', ENT_QUOTES, 'UTF-8');

						//add new row ?>

						<tr>
							<td colspan="2" style="text-align:left; padding-left:10px;"><?php echo $html['text_ad_name']; ?></td>
							<td><?php echo $html['sort_keyword_clicks']; ?></td>
							<td><?php echo $html['sort_keyword_click_throughs']; ?></td>
							<td data-sort='<?php echo $ctr_ratio;?>'><?php echo $html['sort_keyword_ctr']; ?></td>
							<td><?php echo $html['sort_keyword_leads']; ?></td>
							<td data-sort='<?php echo $su_ratio;?>'><?php echo $html['sort_keyword_su_ratio']; ?></td>
							<td><?php echo $html['sort_keyword_payout']; ?></td>
							<td><?php echo $html['sort_keyword_epc']; ?></td>
							<td><?php echo $html['sort_keyword_avg_cpc']; ?></td>
							<td><?php echo $html['sort_keyword_income']; ?></td>
							<td>(<?php echo $html['sort_keyword_cost']; ?>)</td>
							<td data-sort='<?php echo $net;?>'><span class="label label-<?php if ($net > 0) { echo 'primary'; } elseif ($net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['sort_keyword_net'] ; ?></span></td>
							<td data-sort='<?php echo $roi;?>'><span class="label label-<?php if ($net > 0) { echo 'primary'; } elseif ($net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['sort_keyword_roi'] ; ?></span></td>
						</tr>

				<?php }
			} 
			error_reporting(0);

			$html['total_clicks'] = htmlentities(number_format($total_clicks), ENT_QUOTES, 'UTF-8');
			$html['total_click_throughs'] = htmlentities(number_format($total_click_throughs), ENT_QUOTES, 'UTF-8');
			$html['total_ctr'] = htmlentities($total_ctr_ratio . '%', ENT_QUOTES, 'UTF-8');
			$html['total_leads'] = htmlentities($total_leads, ENT_QUOTES, 'UTF-8');
			$html['total_su_ratio'] = htmlentities($total_su_ratio . '%', ENT_QUOTES, 'UTF-8');
			$html['total_payout'] =  htmlentities(dollar_format(($total_payout/$total_rows)), ENT_QUOTES, 'UTF-8');
			$html['total_epc'] =  htmlentities(dollar_format($total_epc), ENT_QUOTES, 'UTF-8');
			$html['total_cpc'] =  htmlentities(dollar_format($total_avg_cpc, $cpv), ENT_QUOTES, 'UTF-8');
			$html['total_income'] =  htmlentities(dollar_format($total_income, $cpv), ENT_QUOTES, 'UTF-8');
			$html['total_cost'] =  htmlentities(dollar_format($total_cost, $cpv), ENT_QUOTES, 'UTF-8');
			$html['total_net'] = htmlentities(dollar_format($total_net, $cpv), ENT_QUOTES, 'UTF-8');
			$html['total_roi'] = htmlentities($total_roi . '%', ENT_QUOTES, 'UTF-8');

			?>
		</tbody>
		<tfoot>
			<tr style="background-color: #F8F8F8;" id="totals">
				<td colspan="2" style="text-align:left; padding-left:10px"><strong>Totals for report</strong></td>
				<td><strong><?php echo $html['total_clicks']; ?></strong></td>
				<td><strong><?php echo $html['total_click_throughs']; ?></strong></td>
				<td><strong><?php echo $html['total_ctr']; ?></strong></td>
				<td><strong><?php echo $html['total_leads']; ?></strong></td>
				<td><strong><?php echo $html['total_su_ratio']; ?></strong></td>
				<td><strong><?php echo $html['total_payout']; ?></strong></td>
				<td><strong><?php echo $html['total_epc']; ?></strong></td>
				<td><strong><?php echo $html['total_cpc']; ?></strong></td>
				<td><strong><?php echo $html['total_income']; ?></strong></td>
				<td><strong>(<?php echo $html['total_cost']; ?>)</strong></td>
				<td><span class="label label-<?php if ($total_net > 0) { echo 'primary'; } elseif ($total_net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['total_net']; ?></span></td>
				<td><span class="label label-<?php if ($total_net > 0) { echo 'primary'; } elseif ($total_net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['total_roi']; ?></span></td>
			</tr>
		</tfoot>
	</table>
	</div>
	</div>


<?php 
if ($query['pages'] > 2) { ?>
<div class="row">
<div class="col-xs-12 text-center">
	<div class="pagination" id="table-pages">
	    <ul>
			<?if ($query['offset'] > 0) {
					printf(' <li class="previous"><a class="fui-arrow-left" onclick="loadContent(\'/tracking202/ajax/sort_text_ads.php\',\'%s\',\'%s\');"></a></li>', $i, $html['order']);
				}

				if ($query['pages'] > 1) {
					for ($i=0; $i < $query['pages']-1; $i++) {
						if (($i >= $query['offset'] - 10) and ($i < $query['offset'] + 11)) {
							if ($query['offset'] == $i) { $class = 'class="active"'; }
							printf(' <li %s><a onclick="loadContent(\'/tracking202/ajax/sort_text_ads.php\',\'%s\',\'%s\');">%s</a></li>', $class, $i, $html['order'], $i+1);
							unset($class);
						}
					}
				}

				if ($query['offset'] > 0) {
					printf(' <li class="next"><a class="fui-arrow-right" onclick="loadContent(\'/tracking202/ajax/sort_text_ads.php\',\'%s\',\'%s\');"></a></li>', $query['offset'] - 1, $html['order']);
				}
			?>
		</ul>
	</div>
	</div>
</div>
<?php } ?>

<script type="text/javascript">
	new Tablesort(document.getElementById('stats-table'));
</script>
