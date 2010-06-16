<? 

include_once('/home/admin202/private_202files/connect-dashboard.php');

AUTH::require_user(); ?>  

<table cellspacing="0" cellpadding="0" class="legend">
	<tr>
		<th colspan="4"><h3 >Today's Stats - <? echo date('g:ia'); ?></h3></th>
	</tr>

	<?
		$mysql['user_id'] = mysql_real_escape_string($_SESSION['user_id']);
		$mysql['from_date'] = mysql_real_escape_string(date('Y-m-d'));
		$mysql['to_date'] = mysql_real_escape_string(date('Y-m-d'));
		$stat_sql = "	SELECT 	stat_account_nickname,
								stat_network_name,
								SUM(stat_impressions) AS stat_impressions, 
								SUM(stat_clicks) AS stat_clicks, 
								SUM(stat_actions) AS stat_actions, 
								SUM(stat_total) AS stat_total,
								(stat_total / stat_clicks) AS stat_epc
					FROM 		api_stats 
					LEFT JOIN 	api_stat_accounts USING (stat_account_id) 
					LEFT JOIN 	api_stat_networks USING (stat_network_id) 
					WHERE 	api_stat_accounts.user_id='".$mysql['user_id']."' 
					AND 		stat_account_deleted='0' 
					AND		stat_date >= '".$mysql['from_date']."'
					AND		stat_date <= '".$mysql['to_date']."'
					AND		stat_total > '0'
					GROUP BY	stat_account_id
					ORDER BY stat_total DESC";
	$stat_result = _mysql_query($stat_sql, $db2StatsLink);
	while ($stat_row = mysql_fetch_assoc($stat_result)) { 

		$clicks = $stat_row['stat_clicks'];
		$actions = $stat_row['stat_actions'];
		$impressions = $stat_row['stat_impressions'];
		$total = $stat_row['stat_total'];
		$epc = $stat_row['stat_epc'];
		
		$total_clicks = $total_clicks + $clicks;
		$total_actions = $total_actions + $actions;
		$total_impressions = $total_impressions + $impressions;
		$total_total =$total_total + $total;
		$total_epc = @($total_total / $total_clicks);

		if ($clicks > 0) { $clicks = number_format($clicks); } else { $clicks = '-'; }
		if ($actions > 0) { $actions = number_format($actions);} else { $actions = '-'; }
		if ($impressions > 0) { $impressions = number_format($impressions);} else { $impressions = '-'; }
		if ($total > 0) { $total = '$'.number_format($total, 2);} else { $total = '-'; }
		if ($epc > 0) { $epc = '$'.number_format($epc, 2);} else { $epc = '-'; }
		
		$name = $stat_row['stat_account_nickname'];
		if (strlen($name) > 20) { $name = substr($name,0,20).'...'; }  
		
		$html['stat_name'] = htmlentities($name); 
		if ($_SESSION['user_username'] == 'demo') { 
			$html['stat_name'] = $html['stat_network_name'];
		}
		?>
		
		<tr class="<? echo $html['row_class']; ?>">
			<td class="left"><? echo $html['stat_name']; ?></td>
			<!--<td class="right"><? echo $clicks; ?></td>  
			<td class="right"><? echo $actions; ?></td>-->
			<td class="right"><? echo $total; ?></td>
		</tr>
		
	<? } 
	
	//show totals at the bottom
	if ($total_clicks > 0) { $total_clicks = number_format($total_clicks); } else { $total_clicks = '-'; }
	if ($total_actions > 0) { $total_actions = number_format($total_actions); } else { $total_actions = '-'; }
	if ($total_impressions > 0) { $total_impressions = number_format($total_impressions); } else { $total_impressions = '-'; }
	if ($total_total > 0) { $total_total = '$'.number_format($total_total,2);  } else { $total_total = '-'; }  
	if ($total_epc > 0) { $total_epc = '$'.number_format($total_epc,2);  } else { $total_epc = '-'; }  ?>
	
	<tr class="bottom">
		<td class="left"><strong>Totals</strong></td>
		<!--<td class="right"><? echo $total_clicks; ?></td>  
		<td class="right"><? echo $total_actions; ?></td>-->
		<td class="right"><strong><? echo $total_total; ?></strong></td>
	</tr>
</table>



<? 

include_once('/home/admin202/private_202files/connect-end.php');