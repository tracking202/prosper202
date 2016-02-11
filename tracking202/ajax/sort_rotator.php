<?php include_once(substr(dirname( __FILE__ ), 0,-17) . '/202-config/connect.php');

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

	if ($user_row['user_pref_show'] == 'all') { $click_filtered = ''; }
	if ($user_row['user_pref_show'] == 'real') { $click_filtered = " AND click_filtered='0' "; }
	if ($user_row['user_pref_show'] == 'filtered') { $click_filtered = " AND click_filtered='1' "; }
	if ($user_row['user_pref_show'] == 'filtered_bot') { $click_filtered = " AND click_bot='1' "; }
	if ($user_row['user_pref_show'] == 'leads') { $click_filtered = " AND click_lead='1' "; }

	if ($user_row['user_cpc_or_cpv'] == 'cpv')  $cpv = true;
	else 										$cpv = false;

			$info_sql = "SELECT * FROM 202_rotators WHERE user_id='".$mysql['user_id']."'";
			$info_result = $db->query($info_sql) or record_mysql_error($info_sql); 
			?>

			<div class="row">
			<div class="col-xs-12" style="margin-top: 10px;">
			<table class="table table-bordered" id="stats-table">
				<thead>
				<tr style="background-color: #f2fbfa;">
					<th colspan="2" style="text-align:left" class="no-sort">Rotator</th>
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
				</tr>
				</thead>
				<tbody>

			<?php
				//to show "0" when no stats	
				$stats_total['clicks'] = 0;
				$stats_total['leads'] = 0;

			while ($rotator_row = $info_result->fetch_array(MYSQLI_ASSOC)) {
				$rotator_totals_sql = "SELECT 
											 COUNT(*) AS clicks, 
											 SUM(2c.click_lead) AS leads, 
											 2ac.aff_campaign_payout AS payout,
											 SUM(2c.click_payout*2c.click_lead) AS income,
											 AVG(2c.click_cpc) AS avg_cpc, 
											 SUM(2c.click_cpc) AS cost
									   FROM 202_clicks as 2c
									   LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
									   WHERE rotator_id='".$rotator_row['id']."'";
				$rotator_totals_sql .= $click_filtered;
				$rotator_totals_sql .= " AND click_time >= '".$mysql['from']."' AND click_time <= '".$mysql['to']."'";
				$rotator_totals_result = $db->query($rotator_totals_sql) or record_mysql_error($rotator_totals_sql);
				$rotator_totals_row = $rotator_totals_result->fetch_array(MYSQLI_ASSOC);

				//clicks
				$clicks = 0;
				$clicks = $rotator_totals_row['clicks'];
				$total_clicks = $total_clicks + $clicks;

				//leads
				$leads = 0;
				$leads = $rotator_totals_row['leads'];
				$total_leads = $total_leads + $leads;

				//payout
				$payout = 0;
				$payout = $rotator_totals_row['payout'];
				$total_payout = $total_payout + $payout;

				//su ration
				$su_ratio = 0;
				$su_ratio = @round($leads/$clicks*100,2);
				$total_su_ratio = @round($total_leads/$total_clicks*100,2);

				//cost
				$cost = 0;
				$cost = $rotator_totals_row['cost'];
				$total_cost = $total_cost + $cost;

				//avg cpc
				$avg_cpc = 0;
				$avg_cpc = $rotator_totals_row['avg_cpc'];
				$total_avg_cpc = @round($total_cost/$total_clicks, 5);

				//income
				$income = 0;
				$income = $rotator_totals_row['income'];
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

				$html['rotator_name'] = htmlentities($rotator_row['name'], ENT_QUOTES, 'UTF-8');
				$html['rotator_clicks'] = htmlentities(number_format($clicks), ENT_QUOTES, 'UTF-8');
				$html['rotator_leads'] = htmlentities(number_format($leads), ENT_QUOTES, 'UTF-8');
				$html['rotator_su_ratio'] = htmlentities($su_ratio.'%', ENT_QUOTES, 'UTF-8');
				$html['rotator_payout'] = htmlentities(dollar_format($payout), ENT_QUOTES, 'UTF-8');
				$html['rotator_epc'] = htmlentities(dollar_format($epc), ENT_QUOTES, 'UTF-8');
				$html['rotator_avg_cpc'] = htmlentities(dollar_format($avg_cpc, $cpv), ENT_QUOTES, 'UTF-8');
				$html['rotator_income'] = htmlentities(dollar_format($income, $cpv), ENT_QUOTES, 'UTF-8');
				$html['rotator_cost'] = htmlentities(dollar_format($cost, $cpv), ENT_QUOTES, 'UTF-8');
				$html['rotator_net'] = htmlentities(dollar_format($net, $cpv), ENT_QUOTES, 'UTF-8');
				$html['rotator_roi'] = htmlentities($roi.'%', ENT_QUOTES, 'UTF-8');
				$html['rotator_cost_wrapper'] = '('.$html['rotator_cost'].')'; 

				if (!$userObj->hasPermission("access_to_campaign_data")) {
					$html['rotator_clicks'] = '?';
					$html['rotator_leads'] = '?';
					$html['rotator_income'] = '?';
					$html['rotator_cost_wrapper'] = '?';
					$html['rotator_net'] = '?';
				}

				?>

				<tr style="background-color: #F8F8F8;" class="no-sort">
					<td colspan="2" style="text-align:left; padding-left:10px;"><strong><?php echo $html['rotator_name'];?></strong></td>
					<td><?php echo $html['rotator_clicks']; ?></td>
					<td><?php echo $html['rotator_leads']; ?></td> 
					<td><?php echo $html['rotator_su_ratio']; ?></td>
					<td><?php echo $html['rotator_payout']; ?></td> 
					<td><?php echo $html['rotator_epc']; ?></td>
					<td><?php echo $html['rotator_avg_cpc']; ?></td>
					<td><?php echo $html['rotator_income']; ?></td>
					<td><?php echo $html['rotator_cost_wrapper']; ?></td>
					<td><span class="label label-<?php if ($net > 0) { echo 'primary'; } elseif ($net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['rotator_net'] ; ?></span></td>
					<td><span class="label label-<?php if ($net > 0) { echo 'primary'; } elseif ($net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['rotator_roi'] ; ?></span></td>

						<?php
							$rule_sql = "SELECT * FROM 202_rotator_rules WHERE rotator_id='".$rotator_row['id']."'";
							$rule_result = $db->query($rule_sql) or record_mysql_error($rule_sql);
							$rows = $rule_result->num_rows;

							while ($rule_row = $rule_result->fetch_assoc()) {

								$rule_stats_sql = "SELECT 
											 COUNT(*) AS clicks, 
											 SUM(2c.click_lead) AS leads, 
											 2ac.aff_campaign_payout AS payout, 
											 SUM(2c.click_payout*2c.click_lead) AS income,
											 AVG(2c.click_cpc) AS avg_cpc, 
											 SUM(2c.click_cpc) AS cost
									   FROM 202_clicks as 2c
									   LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
									   WHERE rule_id='".$rule_row['id']."'";
								$rule_stats_sql .= $click_filtered;
								$rule_stats_sql .= " AND click_time >= '".$mysql['from']."' AND click_time <= '".$mysql['to']."'";
								$rule_stats_result = $db->query($rule_stats_sql) or record_mysql_error($rule_stats_sql);
								$rule_stats_row = $rule_stats_result->fetch_assoc();
								
										$rule_su_ratio = @round($rule_stats_row['leads']/$rule_stats_row['clicks']*100,2);
										$rule_epc = @round($rule_stats_row['income']/$rule_stats_row['clicks'],2);
										$rule_net = $rule_stats_row['income'] - $rule_stats_row['cost'];
										$rule_roi = @round(($rule_net/$rule_stats_row['cost']*100),2); 

										$html['rule_name'] = htmlentities($rule_row['rule_name'], ENT_QUOTES, 'UTF-8');
										$html['rule_clicks'] = htmlentities(number_format($rule_stats_row['clicks']), ENT_QUOTES, 'UTF-8');
										$html['rule_leads'] = htmlentities(number_format($rule_stats_row['leads']), ENT_QUOTES, 'UTF-8');
										$html['rule_su_ratio'] = htmlentities($rule_su_ratio.'%', ENT_QUOTES, 'UTF-8');
										$html['rule_payout'] = htmlentities(dollar_format($rule_stats_row['payout']), ENT_QUOTES, 'UTF-8');
										$html['rule_epc'] = htmlentities(dollar_format($rule_epc), ENT_QUOTES, 'UTF-8');
										$html['rule_avg_cpc'] = htmlentities(dollar_format($rule_stats_row['avg_cpc'], $cpv), ENT_QUOTES, 'UTF-8');
										$html['rule_income'] = htmlentities(dollar_format($rule_stats_row['income'], $cpv), ENT_QUOTES, 'UTF-8');
										$html['rule_cost'] = htmlentities(dollar_format($rule_stats_row['cost'], $cpv), ENT_QUOTES, 'UTF-8');
										$html['rule_net'] = htmlentities(dollar_format($rule_net, $cpv), ENT_QUOTES, 'UTF-8');
										$html['rule_roi'] = htmlentities($rule_roi .'%', ENT_QUOTES, 'UTF-8');
										$html['rule_cost_wrapper'] = '('.$html['rule_cost'].')'; 

										if (!$userObj->hasPermission("access_to_campaign_data")) {
											$html['rule_clicks'] = '?';
											$html['rule_leads'] = '?';
											$html['rule_income'] = '?';
											$html['rule_cost_wrapper'] = '?';
											$html['rule_net'] = '?';
										}

										?>
									<tr>
										<td colspan="2" style="text-align:left; padding-left:20px;"><?php echo $html['rule_name'];?> (<a style="cursor:pointer" id="rule_details" data-id="<?php echo $rule_row['id'];?>" data-toggle="modal" data-target="#rule_values_modal">details</a>)</td>
										<td><?php echo $html['rule_clicks']; ?></td>
										<td><?php echo $html['rule_leads']; ?></td>
										<td><?php echo $html['rule_su_ratio']; ?></td>
										<td><?php echo $html['rule_payout']; ?></td>
										<td><?php echo $html['rule_epc']; ?></td>
										<td><?php echo $html['rule_avg_cpc']; ?></td>
										<td><?php echo $html['rule_income']; ?></td>
										<td><?php echo $html['rule_cost_wrapper']; ?></td>
										<td><span class="label label-<?php if ($rule_net > 0) { echo 'primary'; } elseif ($rule_net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['rule_net'] ; ?></span></td>
										<td><span class="label label-<?php if ($rule_net > 0) { echo 'primary'; } elseif ($rule_net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['rule_roi'] ; ?></span></td>
									</tr>

							<?php }

										$default_stats_sql = "SELECT 
													 COUNT(*) AS clicks, 
													 SUM(2c.click_lead) AS leads, 
													 2ac.aff_campaign_payout AS payout,
													 SUM(2c.click_payout*2c.click_lead) AS income,
													 AVG(2c.click_cpc) AS avg_cpc, 
													 SUM(2c.click_cpc) AS cost
											   FROM 202_clicks as 2c
											   LEFT OUTER JOIN 202_aff_campaigns AS 2ac ON (2c.aff_campaign_id = 2ac.aff_campaign_id)
											   WHERE rotator_id='".$rotator_row['id']."'";
										$default_stats_sql .= $click_filtered;
										$default_stats_sql .= " AND rule_id='0' AND click_time >= '".$mysql['from']."' AND click_time <= '".$mysql['to']."'";
										$default_stats_result = $db->query($default_stats_sql) or record_mysql_error($default_stats_sql);
										$default_stats_row = $default_stats_result->fetch_assoc();

										$default_su_ratio = @round($default_stats_row['leads']/$default_stats_row['clicks']*100,2);
										$default_epc = @round($default_stats_row['income']/$default_stats_row['clicks'],2);
										$default_net = $default_stats_row['income'] - $default_stats_row['cost'];
										$default_roi = @round(($default_net/$default_stats_row['cost']*100),2); 

										$html['default_clicks'] = htmlentities(number_format($default_stats_row['clicks']), ENT_QUOTES, 'UTF-8');
										$html['default_leads'] = htmlentities(number_format($default_stats_row['leads']), ENT_QUOTES, 'UTF-8');
										$html['default_su_ratio'] = htmlentities($default_su_ratio.'%', ENT_QUOTES, 'UTF-8');
										$html['default_payout'] = htmlentities(dollar_format($default_stats_row['payout']), ENT_QUOTES, 'UTF-8');
										$html['default_epc'] = htmlentities(dollar_format($default_epc), ENT_QUOTES, 'UTF-8');
										$html['default_avg_cpc'] = htmlentities(dollar_format($default_stats_row['avg_cpc'], $cpv), ENT_QUOTES, 'UTF-8');
										$html['default_income'] = htmlentities(dollar_format($default_stats_row['income'], $cpv), ENT_QUOTES, 'UTF-8');
										$html['default_cost'] = htmlentities(dollar_format($default_stats_row['cost'], $cpv), ENT_QUOTES, 'UTF-8');
										$html['default_net'] = htmlentities(dollar_format($default_net, $cpv), ENT_QUOTES, 'UTF-8');
										$html['default_roi'] = htmlentities($default_roi .'%', ENT_QUOTES, 'UTF-8'); 

										$html['default_cost_wrapper'] = '('.$html['default_cost'].')'; 

										if (!$userObj->hasPermission("access_to_campaign_data")) {
											$html['default_clicks'] = '?';
											$html['default_leads'] = '?';
											$html['default_income'] = '?';
											$html['default_cost_wrapper'] = '?';
											$html['default_net'] = '?';
										}

										?>

										<tr class="no-sort">
											<td colspan="2" style="text-align:left; padding-left:20px;">Defaults</td>
											<td><?php echo $html['default_clicks']; ?></td>
											<td><?php echo $html['default_leads']; ?></td>
											<td><?php echo $html['default_su_ratio']; ?></td>
											<td><?php echo $html['default_payout']; ?></td>
											<td><?php echo $html['default_epc']; ?></td>
											<td><?php echo $html['default_avg_cpc']; ?></td>
											<td><?php echo $html['default_income']; ?></td>
											<td><?php echo $html['default_cost_wrapper']; ?></td>
											<td><span class="label label-<?php if ($default_net > 0) { echo 'primary'; } elseif ($default_net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['default_net'] ; ?></span></td>
											<td><span class="label label-<?php if ($default_net > 0) { echo 'primary'; } elseif ($default_net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['default_roi'] ; ?></span></td>
										</tr>
				</tr>
			<?php } ?>

			<?php 				$total_payout = $total_payout + $default_stats_row['payout'];
				$rows = $rows + 1;
				$total_payout = @round(($total_payout/$rows),2);	
				$html['total_clicks'] = htmlentities(number_format($total_clicks), ENT_QUOTES, 'UTF-8');
				$html['total_leads'] = htmlentities(number_format($total_leads), ENT_QUOTES, 'UTF-8');
				$html['total_su_ratio'] = htmlentities($total_su_ratio . '%', ENT_QUOTES, 'UTF-8');
				$html['total_payout'] =  htmlentities(dollar_format($total_payout), ENT_QUOTES, 'UTF-8');
				$html['total_epc'] =  htmlentities(dollar_format($total_epc), ENT_QUOTES, 'UTF-8');
				$html['total_cpc'] =  htmlentities(dollar_format($total_avg_cpc, $cpv), ENT_QUOTES, 'UTF-8');
				$html['total_income'] =  htmlentities(dollar_format($total_income, $cpv), ENT_QUOTES, 'UTF-8');
				$html['total_cost'] =  htmlentities(dollar_format($total_cost, $cpv), ENT_QUOTES, 'UTF-8');
				$html['total_net'] = htmlentities(dollar_format($total_net, $cpv), ENT_QUOTES, 'UTF-8');
				$html['total_roi'] = htmlentities($total_roi . '%', ENT_QUOTES, 'UTF-8');

				$html['total_cost_wrapper'] = '('.$html['total_cost'].')'; 

				if (!$userObj->hasPermission("access_to_campaign_data")) {
					$html['total_clicks'] = '?';
					$html['total_leads'] = '?';
					$html['total_income'] = '?';
					$html['total_cost_wrapper'] = '?';
					$html['total_net'] = '?';
				}

				?>

				<tr style="background-color: #F8F8F8;" id="totals" class="no-sort">
					<td colspan="2" style="text-align:left; padding-left:10px"><strong>Totals for report</strong></td>
					<td><strong><?php echo $html['total_clicks']; ?></strong></td>
					<td><strong><?php echo $html['total_leads']; ?></strong></td>
					<td><strong><?php echo $html['total_su_ratio']; ?></strong></td>
					<td><strong><?php echo $html['total_payout']; ?></strong></td>
					<td><strong><?php echo $html['total_epc']; ?></strong></td>
					<td><strong><?php echo $html['total_cpc']; ?></strong></td>
					<td><strong><?php echo $html['total_income']; ?></strong></td>
					<td><strong><?php echo $html['total_cost_wrapper']; ?></strong></td>
					<td><span class="label label-<?php if ($total_net > 0) { echo 'primary'; } elseif ($total_net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['total_net']; ?></span></td>
					<td><span class="label label-<?php if ($total_net > 0) { echo 'primary'; } elseif ($total_net < 0) { echo 'important'; } else { echo 'default'; } ?>"><?php echo $html['total_roi']; ?></span></td>
				</tr>

<div id="rule_values_modal" class="modal fade" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
              <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
              <h4 class="modal-title">Rule details</h4>
            </div>
            <div class="modal-body">
            </div>
            <div class="modal-footer">
              <button class="btn btn-wide btn-default" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>